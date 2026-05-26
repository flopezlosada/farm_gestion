<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketRepository;
use App\Repository\WeeklyBasketStatusRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aplica un cambio puntual de viernes y sus consecuencias en cascada.
 *
 * Una sola transacción cubre:
 *   - Persistir/borrar PartnerDeliveryShift (fuente de verdad del intercambio).
 *   - Ajustar WeeklyBasket del Basket origen (a "no recoge" status=2 al crear;
 *     volver a "recoge" status=1 al cancelar).
 *   - Crear o borrar WeeklyBasket del Basket destino.
 *   - Registrar PartnerEvent::TYPE_WEEK_SWAP (con flag `cancelled` en payload
 *     para reversiones), preservando el audit trail.
 *
 * Este servicio NO valida — el controller debe llamar antes a
 * DeliveryShiftValidator y decidir si bloquea o sigue. El applier asume
 * que la decisión está ya tomada.
 */
final class DeliveryShiftApplier
{
    private const STATUS_PICKED = 1;
    private const STATUS_SKIPPED = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Crea el cambio puntual y aplica la cascada.
     *
     * @param string|null $actor Quien origina el cambio (ver PartnerEvent::$actor).
     * @return PartnerDeliveryShift El shift recién creado, ya persistido.
     * @throws \LogicException Si el partner ya tiene un shift saliendo del `from`
     *                        o entrando al `to`.
     */
    public function apply(Partner $partner, Basket $from, Basket $to, ?string $actor = null): PartnerDeliveryShift
    {
        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);

        if ($shiftRepo->findOutgoing($partner, $from) !== null) {
            throw new \LogicException('Este socio ya tiene un cambio puntual saliendo del viernes origen.');
        }
        if ($shiftRepo->findIncoming($partner, $to) !== null) {
            throw new \LogicException('Este socio ya tiene un cambio puntual entrando al viernes destino.');
        }

        $shift = new PartnerDeliveryShift($partner, $from, $to);
        $this->em->persist($shift);

        $this->cascadeOnApply($partner, $from, $to);
        $this->recordEvent($partner, $from, $to, $actor, cancelled: false);

        $this->em->flush();

        return $shift;
    }

    /**
     * Cancela un cambio puntual existente y revierte la cascada.
     *
     * @param string|null $actor Quien origina la cancelación.
     */
    public function cancel(PartnerDeliveryShift $shift, ?string $actor = null): void
    {
        $partner = $shift->getPartner();
        $from = $shift->getFromBasket();
        $to = $shift->getToBasket();

        $this->cascadeOnCancel($partner, $from, $to);
        $this->em->remove($shift);
        $this->recordEvent($partner, $from, $to, $actor, cancelled: true);

        $this->em->flush();
    }

    /**
     * Aplica los efectos sobre WeeklyBasket al crear el shift:
     *   - WB(from, partner) → status "no recoge" (si existe).
     *   - WB(to, partner)   → crear con status "recoge", PERO solo si el
     *     listado del Basket destino ya estaba generado.
     *
     * Si el listado del destino aún NO se ha generado (no hay ninguna WB
     * en él), no creamos nada para el destino: lo hará WeeklyBasketGenerator
     * al procesarlo, leyendo el shift como fuente de verdad. Esto evita
     * dejar el listado destino con una sola WB huérfana, que dispararía
     * la rama `reuseExistingWeeklyBaskets` del generator y omitiría a los
     * candidatos normales.
     */
    private function cascadeOnApply(Partner $partner, Basket $from, Basket $to): void
    {
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        /** @var WeeklyBasketStatusRepository $statusRepo */
        $statusRepo = $this->em->getRepository(WeeklyBasketStatus::class);

        $existingFrom = $wbRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        if ($existingFrom !== null) {
            $existingFrom->setWeeklyBasketStatus($statusRepo->find(self::STATUS_SKIPPED));
        }

        $existingTo = $wbRepo->findOneBy(['basket' => $to, 'partner' => $partner]);
        if ($existingTo !== null) {
            return;
        }

        $toBasketListed = $wbRepo->findOneBy(['basket' => $to]) !== null;
        if (!$toBasketListed) {
            return;
        }

        $this->createWeeklyBasketForShiftDestination($partner, $to);
    }

    /**
     * Crea la WeeklyBasket en el Basket destino del shift, configurada con
     * el share activo del socio. Llamada tanto por la cascada del applier
     * (cuando el listado ya estaba generado) como por el WeeklyBasketGenerator
     * (cuando procesa el listado por primera vez con shifts entrantes).
     */
    public function createWeeklyBasketForShiftDestination(Partner $partner, Basket $to): WeeklyBasket
    {
        /** @var WeeklyBasketStatusRepository $statusRepo */
        $statusRepo = $this->em->getRepository(WeeklyBasketStatus::class);

        $wb = new WeeklyBasket();
        $wb->setBasket($to);
        $wb->setPartner($partner);
        $wb->setWeeklyBasketStatus($statusRepo->find(self::STATUS_PICKED));
        $wb->setWeeklyBasketGroup($partner->getWeeklyBasketGroup());

        // Congelar la fecha física de entrega usando el nodo del partner.
        // Si no hay nodo (datos legacy), se asume Torremocha y se copia
        // basket.date. Para nodos biweekly el shift implica que el admin
        // confirma el cambio aunque la cadencia del nodo no aplicaría aquí.
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node !== null) {
            $physical = $this->nodeDeliveryDate->physicalDateFor($to, $node);
            $wb->setDeliveryDate($physical ?? $to->getDate());
        } else {
            $wb->setDeliveryDate($to->getDate());
        }

        /** @var PartnerBasketShareRepository $shareRepo */
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);
        $share = $shareRepo->findActiveForPartner($partner);
        if ($share !== null) {
            $wb->setBasketShare($share->getBasketShare());
            $wb->setAmount($share->getAmount());
        } else {
            $wb->setAmount(1);
        }

        $this->em->persist($wb);
        return $wb;
    }

    /**
     * Revierte los efectos sobre WeeklyBasket al cancelar el shift:
     *   - WB(from, partner) → vuelve a "recoge" (si estaba en "no recoge").
     *   - WB(to, partner)   → se borra (asume que se creó por el shift).
     *
     * Si WB(from) estuviera en otro status (p. ej. status 3 "atrasada"), se
     * deja como está — el shift no la creó así y no sabemos si revertirla
     * sería correcto.
     */
    private function cascadeOnCancel(Partner $partner, Basket $from, Basket $to): void
    {
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        /** @var WeeklyBasketStatusRepository $statusRepo */
        $statusRepo = $this->em->getRepository(WeeklyBasketStatus::class);

        $existingFrom = $wbRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        if ($existingFrom !== null && $existingFrom->getWeeklyBasketStatus()?->getId() === self::STATUS_SKIPPED) {
            $existingFrom->setWeeklyBasketStatus($statusRepo->find(self::STATUS_PICKED));
        }

        $existingTo = $wbRepo->findOneBy(['basket' => $to, 'partner' => $partner]);
        if ($existingTo !== null) {
            $this->em->remove($existingTo);
        }
    }

    private function recordEvent(Partner $partner, Basket $from, Basket $to, ?string $actor, bool $cancelled): void
    {
        $event = new PartnerEvent($partner, PartnerEvent::TYPE_WEEK_SWAP);
        $event->setPayload([
            'from_basket_id' => $from->getId(),
            'from_date' => $from->getDate()?->format('Y-m-d'),
            'to_basket_id' => $to->getId(),
            'to_date' => $to->getDate()?->format('Y-m-d'),
            'cancelled' => $cancelled,
        ]);
        if ($actor !== null) {
            $event->setActor($actor);
        }
        $this->em->persist($event);
    }
}
