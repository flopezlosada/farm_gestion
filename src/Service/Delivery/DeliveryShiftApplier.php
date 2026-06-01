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
        private readonly WeeklyBasketComposer $composer,
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
    /**
     * @param array<int, array{component: \App\Entity\BasketComponent, amount: string}>|null $carryItems
     *        Composición EXACTA a estampar en el destino (la que llevaba la cesta que
     *        se mueve, con verdura/huevos quitados a mano ya reflejados). Si es null se
     *        deriva del patrón (caso de una cesta sólo prevista, sin personalizar).
     */
    public function apply(Partner $partner, Basket $from, Basket $to, ?string $actor = null, ?array $carryItems = null): PartnerDeliveryShift
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

        $this->cascadeOnApply($partner, $from, $to, $carryItems);
        $this->recordEvent($partner, $from, $to, $actor, cancelled: false);

        $this->em->flush();

        return $shift;
    }

    /**
     * Mueve la cesta que el socio tiene en `$current` a `$target`, sin concepto de
     * "deshacer": mover de vuelta a su día de patrón es simplemente otro destino.
     *
     * Resuelve el día de PATRÓN de la cesta: si `$current` es el destino de un
     * cambio puntual (la cesta ya venía movida), su patrón es el origen de ese
     * cambio; si no, el propio `$current`. Entonces:
     *   - target == patrón  → cancela el cambio: la cesta vuelve a su día natural.
     *   - target != patrón  → reapunta el cambio (cancela el previo si lo hay y
     *     crea uno nuevo patrón→target). El huevo viaja con la cesta porque el
     *     applier compone el destino con el origen de patrón como referencia.
     *
     * @param Partner     $partner
     * @param Basket      $current Día donde la cesta está AHORA (origen del movimiento).
     * @param Basket      $target  Día al que se mueve la cesta.
     * @param string|null $actor   Quien origina el movimiento.
     */
    public function move(Partner $partner, Basket $current, Basket $target, ?string $actor = null): void
    {
        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        // La composición REAL que la cesta lleva AHORA (con lo que se haya quitado a
        // mano) debe viajar con ella. Se captura antes de tocar nada; null = cesta sólo
        // prevista (sin materializar) → el destino se deriva del patrón.
        $currentWb = $wbRepo->findOneBy(['basket' => $current, 'partner' => $partner]);
        $carryItems = $currentWb !== null ? $this->composer->copyLines($currentWb) : null;

        $incoming = $shiftRepo->findIncoming($partner, $current);
        $patternOrigin = $incoming?->getFromBasket() ?? $current;

        if ($incoming !== null) {
            $this->cancel($incoming, $actor);
        }

        if ($target->getId() !== $patternOrigin->getId()) {
            $this->apply($partner, $patternOrigin, $target, $actor, $carryItems);

            return;
        }

        // Volver al día de patrón: el cancel ya restauró su WB. Si la cesta llevaba una
        // composición personalizada, reflejarla también ahí (no re-derivar del patrón).
        if ($carryItems !== null) {
            $home = $wbRepo->findOneBy(['basket' => $patternOrigin, 'partner' => $partner]);
            if ($home !== null) {
                $this->composer->stamp($home, $carryItems);
                $this->em->flush();
            }
        }
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
    /**
     * @param array<int, array{component: \App\Entity\BasketComponent, amount: string}>|null $carryItems
     *        Composición exacta a estampar en el destino (ver apply()).
     */
    private function cascadeOnApply(Partner $partner, Basket $from, Basket $to, ?array $carryItems = null): void
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
            // El destino ya tiene un WB: típicamente el "no recoge" de ORIGEN de otro
            // cambio puntual (día vaciado porque su cesta se fue a otra fecha, pero
            // LIBRE para recibir esta). El controller solo deja llegar aquí destinos
            // libres o vaciados; un "no recoge" de verdad se rechaza antes. Se revive:
            // se limpian sus líneas viejas, se reconfigura como destino y se recompone.
            $this->reviveAsShiftDestination($existingTo, $partner, $to, $from, $carryItems);

            return;
        }

        $toBasketListed = $wbRepo->findOneBy(['basket' => $to]) !== null;
        if (!$toBasketListed) {
            return;
        }

        // Estampar también los componentes del destino. El generador ya lo hace
        // al procesar el listado; aquí (aplicación ad-hoc desde el calendario) hay
        // que hacerlo o la entrega movida sale sin verdura/huevos (vacía → roja).
        $destWb = $this->createWeeklyBasketForShiftDestination($partner, $to);
        $this->stampDestination($destWb, $partner, $to, $from, $carryItems);
    }

    /**
     * Estampa la composición del destino de un cambio: si la cesta traía una
     * composición REAL ($carryItems, con lo quitado a mano), se copia tal cual
     * (viaja con la cesta). Si no (cesta sólo prevista), se deriva del patrón con
     * los huevos del ORIGEN como referencia (caso Franco: el huevo viaja igualmente).
     *
     * @param array<int, array{component: \App\Entity\BasketComponent, amount: string}>|null $carryItems
     */
    private function stampDestination(WeeklyBasket $destWb, Partner $partner, Basket $to, Basket $from, ?array $carryItems): void
    {
        if ($carryItems !== null) {
            $this->composer->stamp($destWb, $carryItems);

            return;
        }

        $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $to->getDate());
        if ($share !== null) {
            $this->composer->compose($destWb, $share, $to, eggReferenceBasket: $from);
        }
    }

    /**
     * Reconfigura un WeeklyBasket ya existente (el "no recoge" de origen de otro
     * cambio, día vaciado) para que reciba la cesta que se mueve aquí: limpia sus
     * líneas viejas, lo pone en "recoge" con la fecha/modalidad del destino y estampa
     * su composición (la que traía la cesta, o el patrón si sólo estaba prevista).
     *
     * @param array<int, array{component: \App\Entity\BasketComponent, amount: string}>|null $carryItems
     */
    private function reviveAsShiftDestination(WeeklyBasket $wb, Partner $partner, Basket $to, Basket $from, ?array $carryItems = null): void
    {
        $this->removeItemsOf($wb);
        $this->em->flush(); // que la idempotencia de compose() vea el WB sin líneas.

        $this->configureAsShiftDestination($wb, $partner, $to);
        $this->stampDestination($wb, $partner, $to, $from, $carryItems);
    }

    /**
     * Materializa (idempotente) la entrega DESTINO de un cambio puntual aún solo
     * proyectado, llevándose la composición REAL del origen: lo quitado a mano y, sobre
     * todo, los huevos que viajan con la cesta movida. Es la contraparte de
     * {@see WeeklyBasketGenerator::materializeShareDelivery()} para un destino de shift,
     * que NO debe componerse desde el patrón de su propia semana (la perdería los huevos
     * si esa semana no es de huevo) sino desde el origen. La usa la edición del
     * calendario antes de saltar o tocar componentes en un día que recibe una cesta movida.
     *
     * @param PartnerDeliveryShift $shift Cambio cuyo destino se materializa.
     * @return WeeklyBasket Entrega destino ya persistida y compuesta.
     */
    public function materializeShiftDestination(PartnerDeliveryShift $shift): WeeklyBasket
    {
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        $partner = $shift->getPartner();
        $from = $shift->getFromBasket();
        $to = $shift->getToBasket();

        $existing = $wbRepo->findOneBy(['basket' => $to, 'partner' => $partner]);
        if ($existing !== null) {
            return $existing;
        }

        // Composición que viaja: la real del origen si está materializado (conserva lo
        // quitado a mano); si solo estaba previsto, stampDestination la deriva del patrón
        // con los huevos del origen como referencia.
        $fromWb = $wbRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        $carryItems = $fromWb !== null ? $this->composer->copyLines($fromWb) : null;

        $destWb = $this->createWeeklyBasketForShiftDestination($partner, $to);
        $this->stampDestination($destWb, $partner, $to, $from, $carryItems);
        $this->em->flush();

        return $destWb;
    }

    /**
     * Crea la WeeklyBasket en el Basket destino del shift, configurada con
     * el share activo del socio. Llamada tanto por la cascada del applier
     * (cuando el listado ya estaba generado) como por el WeeklyBasketGenerator
     * (cuando procesa el listado por primera vez con shifts entrantes).
     */
    public function createWeeklyBasketForShiftDestination(Partner $partner, Basket $to): WeeklyBasket
    {
        $wb = new WeeklyBasket();
        $wb->setBasket($to);
        $wb->setPartner($partner);
        $this->configureAsShiftDestination($wb, $partner, $to);
        $this->em->persist($wb);

        return $wb;
    }

    /**
     * Configura un WeeklyBasket como entrega destino de un cambio puntual: estado
     * "recoge", grupo del socio, fecha física según su nodo y modalidad/cantidad de
     * su share activo. Compartido por la creación (WB nuevo) y la reviviscencia (WB
     * de un día vaciado que pasa a recibir esta cesta).
     */
    private function configureAsShiftDestination(WeeklyBasket $wb, Partner $partner, Basket $to): void
    {
        /** @var WeeklyBasketStatusRepository $statusRepo */
        $statusRepo = $this->em->getRepository(WeeklyBasketStatus::class);

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
            // Borrar primero sus líneas: WeeklyBasketItem->weeklyBasket no tiene
            // cascade, así que dejarlas colgando de un WB borrado rompe el siguiente
            // flush ("new entity found through WeeklyBasketItem#weeklyBasket"). Aflora
            // al mover una cesta ya movida (cancel + apply en la misma transacción).
            $this->removeItemsOf($existingTo);
            $this->em->remove($existingTo);
        }
    }

    /**
     * Borra las líneas de componente de un WeeklyBasket. WeeklyBasketItem->weeklyBasket
     * no cascadea, así que hay que retirarlas a mano antes de borrar/recomponer el WB.
     */
    private function removeItemsOf(WeeklyBasket $wb): void
    {
        foreach ($this->em->getRepository(\App\Entity\WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
            $this->em->remove($item);
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
