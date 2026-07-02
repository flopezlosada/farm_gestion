<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
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
final class ExtraBasketEditor implements ExtraBasketAdder
{
    private const STATUS_PICKED = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketComposer $composer,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Añade cestas y/o huevos a la entrega del socio en esa semana como OVERRIDE puntual
     * ({@see PartnerBasketExtra}), hermano del override de nodo. El incremento se ACUMULA
     * sobre lo que ya tuviera de extra esa semana. Cascadea a piedra si el reparto del NODO
     * del socio ya está generado (suma a su entrega, creándola si no le tocaba); si la
     * semana solo está DIBUJADA, basta el override —la proyección lo suma al dibujar y la
     * materialización al congelar—, así funciona también a futuro y sin piedra parcial.
     * Transaccional: hace flush al final.
     *
     * @param Partner            $partner    Socio.
     * @param Basket             $basket     Semana/ciclo de reparto.
     * @param array<int, string> $addAmounts Incremento por componente, [BasketComponent id => cantidad].
     *                                       Las ausentes o <= 0 no añaden nada.
     * @param string|null        $note       Motivo libre del gestor (queda en el evento).
     * @param string|null        $actor      Quién lo origina (ver PartnerEvent::$actor).
     */
    public function addToDelivery(
        Partner $partner,
        Basket $basket,
        array $addAmounts,
        ?string $note = null,
        ?string $actor = null,
    ): void {
        // 1. Override = fuente de verdad. Upsert por componente (acumula sobre lo previo).
        $deltas = $this->upsertOverrides($partner, $basket, $addAmounts);
        if ($deltas === []) {
            return; // nada que añadir (todos los incrementos <= 0).
        }

        // 2. Cascada a PIEDRA solo si la semana ya está materializada para el NODO del socio.
        //    Generada: sumamos a su entrega (creándola si no le tocaba). Dibujada: basta el
        //    override, que la proyección suma al dibujar (no se toca piedra ni se crea parcial).
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $wb = $wbRepo->findOneBy(['basket' => $basket, 'partner' => $partner]);
        if ($wb !== null) {
            $this->composer->addAmounts($wb, $deltas);
        } else {
            $node = $partner->getWeeklyBasketGroup()?->getNode();
            if ($node !== null && $wbRepo->findForNodeAndBasket($node, $basket) !== []) {
                // Semana generada para el nodo y "no le tocaba": crear su entrega y sumar.
                $wb = $this->createDelivery($partner, $basket);
                $this->composer->addAmounts($wb, $deltas);
            }
            // Nodo sin generar: semana dibujada → solo el override (lo aplica la proyección).
        }

        $this->recordEvent($partner, $basket, $addAmounts, $note, $actor);
        $this->em->flush();
    }

    /**
     * ¿Tiene el socio alguna cesta extra (override) esa semana?
     *
     * @param Partner $partner
     * @param Basket  $basket
     * @return bool
     */
    public function hasExtra(Partner $partner, Basket $basket): bool
    {
        return $this->em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket) !== [];
    }

    /**
     * Deshace la cesta extra de un socio esa semana: borra TODOS sus overrides de extra de
     * esa semana y revierte la piedra si está generada. Para una cesta extra, "no recoge"
     * equivale a esto (cancelar el añadido). Transaccional: hace flush al final.
     *
     * Revertir piedra: si la entrega era "solo extra" (sin modalidad, creada para el extra)
     * se borra entera; si tenía base (suscripción), se resta el extra de su composición. En
     * una semana solo DIBUJADA no hay piedra: basta borrar el override (la proyección redibuja).
     *
     * @param Partner     $partner Socio.
     * @param Basket      $basket  Semana/ciclo de reparto.
     * @param string|null $actor   Quién lo origina (ver PartnerEvent::$actor).
     * @return bool true si había algún extra que quitar; false si no había nada.
     */
    public function removeExtra(Partner $partner, Basket $basket, ?string $actor = null): bool
    {
        $extras = $this->em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket);
        if ($extras === []) {
            return false;
        }

        // Deltas a revertir de la piedra y borrado de los overrides (fuente de verdad).
        $deltas = [];
        foreach ($extras as $extra) {
            $component = $extra->getComponent();
            if ($component !== null) {
                $deltas[$component->getId()] = (float) $extra->getAmount();
            }
            $this->em->remove($extra);
        }

        // Cascada a piedra si la semana está materializada para el socio.
        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket, 'partner' => $partner]);
        if ($wb !== null) {
            if ($wb->getBasketShare() === null) {
                // Entrega "solo extra" (sin modalidad): era enteramente el extra → se borra
                // con sus líneas (stamp([]) las limpia antes de quitar el WB).
                $this->composer->stamp($wb, []);
                $this->em->remove($wb);
            } else {
                // Tenía base de suscripción: se resta el extra y queda su cesta de patrón.
                $this->composer->subtractAmounts($wb, $deltas);
            }
        }

        $this->recordRemovalEvent($partner, $basket, $deltas, $actor);
        $this->em->flush();

        return true;
    }

    /**
     * Crea o re-apunta (ACUMULANDO) el override de cesta extra por componente para esa
     * semana, y devuelve los deltas efectivos que se añaden ahora (lo que hay que cascadear
     * a la piedra), descartando los <= 0 y los componentes inexistentes.
     *
     * @param Partner            $partner
     * @param Basket             $basket
     * @param array<int, string> $addAmounts componentId => incremento.
     * @return array<int, float> componentId => delta efectivo (> 0).
     */
    private function upsertOverrides(Partner $partner, Basket $basket, array $addAmounts): array
    {
        $extraRepo = $this->em->getRepository(PartnerBasketExtra::class);
        $componentRepo = $this->em->getRepository(BasketComponent::class);

        $deltas = [];
        foreach ($addAmounts as $componentId => $add) {
            $delta = (float) $add;
            if ($delta <= 0) {
                continue;
            }
            $component = $componentRepo->find($componentId);
            if ($component === null) {
                continue;
            }
            $existing = $extraRepo->findOneForPartnerBasketComponent($partner, $basket, $component);
            if ($existing !== null) {
                $existing->setAmount(number_format((float) $existing->getAmount() + $delta, 2, '.', ''));
            } else {
                $this->em->persist(new PartnerBasketExtra($partner, $basket, $component, number_format($delta, 2, '.', '')));
            }
            $deltas[$componentId] = $delta;
        }

        return $deltas;
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

    /**
     * Deja constancia de la BAJA de una cesta extra en el histórico (PartnerEvent), con las
     * cantidades que se quitaron, para que el histórico no quede mostrando "extra añadida"
     * tras haberla deshecho.
     *
     * @param Partner           $partner
     * @param Basket            $basket
     * @param array<int, float> $removed componentId => cantidad quitada.
     * @param string|null       $actor
     */
    private function recordRemovalEvent(Partner $partner, Basket $basket, array $removed, ?string $actor): void
    {
        $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_EXTRA_REMOVED);
        $event->setPayload([
            'basket_id' => $basket->getId(),
            'basket_date' => $basket->getDate()?->format('Y-m-d'),
            'removed' => $removed,
        ]);
        if ($actor !== null) {
            $event->setActor($actor);
        }
        $this->em->persist($event);
    }
}
