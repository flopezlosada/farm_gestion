<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cesta extra puntual: AÑADE cestas (o huevos) a la entrega de un socio en una semana
 * concreta, creándola si no existía. Es la operación detrás de los dos casos reales
 * (a última hora, sobre el reparto en marcha):
 *  - SUMAR: el socio ya recoge esa semana y se le añaden cestas (o huevos que
 *    normalmente no lleva).
 *  - NO LE TOCABA: el socio no tiene entrega esa semana y se le da una (o varias) cestas.
 *
 * Se razona en términos de "lo que se añade" (incremento), no de total final: el gestor
 * dice "una cesta más", no "que se lleve 3". El incremento se SUMA a lo que el socio ya
 * tuviera esa semana.
 *
 * "Entrega" y "cantidad de cestas" son cosas distintas: una entrega (un WeeklyBasket)
 * puede llevar N cestas de verdura. Por eso esto NUNCA crea un segundo WeeklyBasket
 * para el mismo socio+semana —siempre hay uno solo, igual que un socio de 2 cestas
 * permanentes (amount=2)—, sino que ajusta las líneas de componente de esa única
 * entrega. Así no se toca el motor de generación ni los findOneBy(basket, partner).
 */
final class ExtraBasketEditor
{
    private const STATUS_PICKED = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketComposer $composer,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Añade cestas y/o huevos a la entrega del socio en esa semana (creándola si no
     * existía) y persiste. El incremento se suma a la composición actual. Transaccional:
     * hace flush al final.
     *
     * @param Partner            $partner    Socio.
     * @param Basket             $basket     Semana/ciclo de reparto.
     * @param array<int, string> $addAmounts Incremento por componente, [BasketComponent id => cantidad].
     *                                       Las ausentes o <= 0 no añaden nada.
     * @param string|null        $note       Motivo libre del gestor (queda en el evento).
     * @param string|null        $actor      Quién lo origina (ver PartnerEvent::$actor).
     * @return WeeklyBasket La entrega con la cesta extra ya sumada.
     * @throws \LogicException Si la semana aún no está generada (no hay ninguna entrega en ella):
     *                         una entrega suelta dejaría el listado de esa semana con solo ella.
     */
    public function addToDelivery(
        Partner $partner,
        Basket $basket,
        array $addAmounts,
        ?string $note = null,
        ?string $actor = null,
    ): WeeklyBasket {
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        $wb = $wbRepo->findOneBy(['basket' => $basket, 'partner' => $partner]);
        if ($wb === null) {
            // "No le tocaba": se crea su entrega. Solo es seguro si la semana ya está
            // generada; si no, este WB suelto haría que el generador (rama reuse) trate
            // la semana como completa y deje fuera al resto de socios.
            if ($wbRepo->findOneBy(['basket' => $basket]) === null) {
                throw new \LogicException('No se puede añadir una cesta extra a una semana sin generar: genera primero el listado de esa semana.');
            }
            $wb = $this->createDelivery($partner, $basket);
            $current = [];
        } else {
            $current = $this->currentAmounts($wb);
        }

        $total = $current;
        foreach ($addAmounts as $componentId => $add) {
            $total[$componentId] = ($total[$componentId] ?? 0.0) + (float) $add;
        }

        $this->composer->stamp($wb, $this->linesFrom($total));
        $wb->setAmount($this->vegetableCount($total));

        $this->recordEvent($partner, $basket, $addAmounts, $note, $actor);
        $this->em->flush();

        return $wb;
    }

    /**
     * Composición actual de una entrega como mapa [componentId => cantidad], leyendo
     * sus líneas reales. Base sobre la que se suma el incremento.
     *
     * @param WeeklyBasket $wb
     * @return array<int, float>
     */
    private function currentAmounts(WeeklyBasket $wb): array
    {
        $map = [];
        foreach ($this->composer->copyLines($wb) as $line) {
            $map[$line['component']->getId()] = (float) $line['amount'];
        }

        return $map;
    }

    /**
     * Crea la entrega de un socio en una semana (variante "no le tocaba"): estado
     * "recoge", grupo del socio, fecha física según su nodo (fallback al viernes-ciclo)
     * y modalidad/cantidad de su suscripción activa. Las líneas las fija luego el
     * stamp del llamante; aquí solo se arma la entrega base.
     *
     * @param Partner $partner Socio.
     * @param Basket  $basket  Semana.
     * @return WeeklyBasket Entrega ya persistida (sin líneas todavía).
     */
    private function createDelivery(Partner $partner, Basket $basket): WeeklyBasket
    {
        $statusRepo = $this->em->getRepository(WeeklyBasketStatus::class);
        $shareRepo = $this->em->getRepository(\App\Entity\PartnerBasketShare::class);

        $wb = (new WeeklyBasket())
            ->setBasket($basket)
            ->setPartner($partner)
            ->setWeeklyBasketStatus($statusRepo->find(self::STATUS_PICKED))
            ->setWeeklyBasketGroup($partner->getWeeklyBasketGroup());

        $node = $partner->getWeeklyBasketGroup()?->getNode();
        $physical = $node !== null ? $this->nodeDeliveryDate->physicalDateFor($basket, $node) : null;
        $wb->setDeliveryDate($physical ?? $basket->getDate());

        $share = $shareRepo->findActiveForPartner($partner);
        $wb->setBasketShare($share?->getBasketShare());

        $this->em->persist($wb);

        return $wb;
    }

    /**
     * Convierte el mapa [componentId => cantidad] en las líneas que espera el composer,
     * resolviendo cada BasketComponent y descartando las cantidades nulas o <= 0.
     *
     * @param array<int, float|string> $componentAmounts
     * @return array<int, array{component: BasketComponent, amount: string}>
     */
    private function linesFrom(array $componentAmounts): array
    {
        $componentRepo = $this->em->getRepository(BasketComponent::class);

        $lines = [];
        foreach ($componentAmounts as $componentId => $amount) {
            if ((float) $amount <= 0) {
                continue;
            }
            $component = $componentRepo->find($componentId);
            if ($component !== null) {
                $lines[] = ['component' => $component, 'amount' => number_format((float) $amount, 2, '.', '')];
            }
        }

        return $lines;
    }

    /**
     * Número de cestas (de verdura) que lleva la entrega, para mantener coherente el
     * WeeklyBasket::amount (que alimenta los totales de cabecera) con las líneas. 0 si
     * la entrega es solo de huevos.
     *
     * @param array<int, float|string> $componentAmounts
     * @return int
     */
    private function vegetableCount(array $componentAmounts): int
    {
        return (int) round((float) ($componentAmounts[BasketComponent::ID_VEGETABLES] ?? 0));
    }

    /**
     * Deja constancia de la cesta extra en el histórico del socio (PartnerEvent),
     * registrando lo que se AÑADIÓ (no el total).
     *
     * @param Partner            $partner
     * @param Basket             $basket
     * @param array<int, string> $addAmounts
     * @param string|null        $note
     * @param string|null        $actor
     */
    private function recordEvent(Partner $partner, Basket $basket, array $addAmounts, ?string $note, ?string $actor): void
    {
        $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_EXTRA);
        $event->setPayload([
            'basket_id' => $basket->getId(),
            'basket_date' => $basket->getDate()?->format('Y-m-d'),
            'added' => $addAmounts,
        ]);
        if ($note !== null && $note !== '') {
            $event->setNotes($note);
        }
        if ($actor !== null) {
            $event->setActor($actor);
        }
        $this->em->persist($event);
    }
}
