<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
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
final class DeliveryShiftApplier implements ShiftReconciliationActions
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

        if ($incoming !== null) {
            // La cesta que está en $current llegó por un cambio ($incoming: patrón→current).
            // Moverla NO debe pasar por el día de patrón: ese día puede estar OCUPADO por
            // OTRA cesta movida (ciclo 12→26 + 26→12). Por eso NO se cancela+reaplica
            // (eso saltaría el WB del patrón y perdería la otra cesta), sino que:
            $patternOrigin = $incoming->getFromBasket();
            if ($target->getId() === $patternOrigin->getId()) {
                // Volver al día de patrón = cancelar el cambio (la cesta vuelve a su sitio).
                $this->cancel($incoming, $actor);
                if ($carryItems !== null) {
                    $home = $wbRepo->findOneBy(['basket' => $patternOrigin, 'partner' => $partner]);
                    if ($home !== null) {
                        $this->composer->stamp($home, $carryItems);
                        $this->em->flush();
                    }
                }

                return;
            }

            // Mover a OTRO día = RE-APUNTAR el mismo cambio (patrón→target), sin tocar el
            // día de patrón.
            $this->repoint($incoming, $target, $carryItems, $actor);

            return;
        }

        // $current es día de PATRÓN (su cesta no viene movida): su cesta de patrón se
        // mueve a $target. Mover al propio día = no-op.
        if ($target->getId() === $current->getId()) {
            return;
        }

        $this->apply($partner, $current, $target, $actor, $carryItems);
    }

    /**
     * Re-apunta un cambio puntual ya existente a un nuevo destino, SIN tocar su día de
     * patrón (from). Vacía el destino viejo (la cesta deja ese día) y materializa el
     * nuevo con la composición que viaja. Es la pieza que hace seguros los movimientos
     * ENCADENADOS o en CICLO (p. ej. 12→19 y luego 19→26, o el swap 12↔26): cancelar y
     * reaplicar pasando por el patrón saltaría su WB, que puede pertenecer a otra cesta
     * movida allí.
     *
     * @param PartnerDeliveryShift                                                          $shift      Cambio a re-apuntar (su from se conserva).
     * @param Basket                                                                        $newTarget  Nuevo destino.
     * @param array<int, array{component: \App\Entity\BasketComponent, amount: string}>|null $carryItems Composición que viaja.
     * @param string|null                                                                   $actor
     */
    private function repoint(PartnerDeliveryShift $shift, Basket $newTarget, ?array $carryItems, ?string $actor): void
    {
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        $partner = $shift->getPartner();
        $from = $shift->getFromBasket();
        $oldTarget = $shift->getToBasket();

        // Vaciar el destino VIEJO: la cesta deja ese día (queda libre). Se borran sus
        // líneas antes del WB (WeeklyBasketItem no cascadea).
        $oldWb = $wbRepo->findOneBy(['basket' => $oldTarget, 'partner' => $partner]);
        if ($oldWb !== null) {
            $this->removeItemsOf($oldWb);
            $this->em->remove($oldWb);
        }

        // Re-apuntar el cambio y asentar el borrado/repoint antes de tocar el destino nuevo.
        $shift->setToBasket($newTarget);
        $this->em->flush();

        // Materializar el destino NUEVO con la composición que viaja (solo si su listado
        // ya está generado; si no, basta el shift re-apuntado, que el generador leerá).
        $existingNew = $wbRepo->findOneBy(['basket' => $newTarget, 'partner' => $partner]);
        if ($existingNew !== null) {
            $this->reviveAsShiftDestination($existingNew, $partner, $newTarget, $from, $carryItems);
        } elseif ($wbRepo->findOneBy(['basket' => $newTarget]) !== null) {
            $destWb = $this->createWeeklyBasketForShiftDestination($partner, $newTarget);
            $this->stampDestination($destWb, $partner, $newTarget, $from, $carryItems);
        }

        $this->recordEvent($partner, $from, $newTarget, $actor, cancelled: false);
        $this->em->flush();
    }

    /**
     * Mueve UN COMPONENTE (verdura u huevos) de la entrega de un socio de `$current`
     * a `$target`, sin tocar el resto de su entrega ni los demás componentes (caso
     * SANTOS: mensual de verdura + semanal de huevo, mueve solo la verdura). Misma
     * filosofía que move(): no hay "deshacer"; volver al día de patrón es otro destino.
     *
     * Resuelve el día de PATRÓN del componente: si en `$current` el componente ya
     * venía movido (intent entrante de ese componente), su patrón es el origen de ese
     * intent; si no, el propio `$current`. Entonces:
     *   - target == patrón → cancela el intent (el componente vuelve a su día natural).
     *   - target != patrón → reapunta (cancela el previo y crea uno nuevo patrón→target).
     *
     * La cascada sobre WeeklyBasket solo se aplica si la semana está GENERADA (igual
     * que move()): en meses sin generar basta el intent, que el generador y el
     * proyector ya leen.
     *
     * @param Partner         $partner
     * @param BasketComponent $component Componente a mover (verdura | huevos).
     * @param Basket          $current   Semana donde el componente está ahora.
     * @param Basket          $target    Semana destino.
     * @param string|null     $actor
     */
    public function moveComponent(Partner $partner, BasketComponent $component, Basket $current, Basket $target, ?string $actor = null): void
    {
        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);

        $incoming = $shiftRepo->findComponentIncoming($partner, $current, $component);
        $patternOrigin = $incoming?->getFromBasket() ?? $current;

        if ($incoming !== null) {
            $this->cancelComponent($incoming, $actor);
        }

        if ($target->getId() === $patternOrigin->getId()) {
            // Volver al día de patrón: el cancel ya restauró el componente allí.
            return;
        }

        $shift = new PartnerDeliveryShift($partner, $patternOrigin, $target, $component);
        $this->em->persist($shift);
        $this->cascadeComponentOnApply($partner, $component, $patternOrigin, $target);
        $this->recordEvent($partner, $patternOrigin, $target, $actor, cancelled: false);
        $this->em->flush();
    }

    /**
     * Cascada de WeeklyBasket al crear un intent POR COMPONENTE (solo si la semana
     * está generada): quita el componente en el origen y lo añade en el destino
     * (creando la entrega si esa semana no tenía ninguna). Las semanas sin generar
     * no se tocan — el generador lo aplicará al materializar.
     */
    private function cascadeComponentOnApply(Partner $partner, BasketComponent $component, Basket $from, Basket $to): void
    {
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        $fromWb = $wbRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        if ($fromWb !== null) {
            $this->composer->removeComponent($fromWb, $component);
        }

        if ($wbRepo->findOneBy(['basket' => $to]) === null) {
            return; // listado del destino aún sin generar: solo queda el intent
        }
        $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $to->getDate());
        if ($share === null) {
            return;
        }
        $toWb = $wbRepo->findOneBy(['basket' => $to, 'partner' => $partner]);
        if ($toWb === null) {
            $toWb = $this->createWeeklyBasketForShiftDestination($partner, $to);
            $this->em->flush();
        } elseif ($component->getId() === BasketComponent::ID_VEGETABLES
            && ($toWb->getBasketShare()?->getDeliveredBasketWeight() ?? 0.0) <= 0.0) {
            // Verdura aterrizando en una entrega "solo-huevo": realinear modalidad.
            $toWb->setBasketShare($share->getBasketShare());
            $toWb->setAmount($share->getAmount());
            $toWb->setPartnerBasketShare($share);
        }
        $this->composer->addComponent($toWb, $share, $component);
    }

    /**
     * Cancela un intent POR COMPONENTE y revierte su cascada (si la semana está
     * generada): quita el componente del destino y lo restaura en el origen.
     */
    private function cancelComponent(PartnerDeliveryShift $shift, ?string $actor = null): void
    {
        $partner = $shift->getPartner();
        $component = $shift->getComponent();
        $from = $shift->getFromBasket();
        $to = $shift->getToBasket();

        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        if ($to !== null) {
            $toWb = $wbRepo->findOneBy(['basket' => $to, 'partner' => $partner]);
            if ($toWb !== null) {
                $this->composer->removeComponent($toWb, $component);
            }
        }
        $fromWb = $wbRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        if ($fromWb !== null) {
            $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $from->getDate());
            if ($share !== null) {
                $this->composer->addComponent($fromWb, $share, $component);
            }
        }

        $this->em->remove($shift);
        $this->recordEvent($partner, $from, $to ?? $from, $actor, cancelled: true);
        // Flush AQUÍ: si moveComponent reapunta el mismo componente (cancela el viejo y
        // crea uno nuevo desde el mismo origen), el DELETE debe persistir ANTES del
        // INSERT — si no, ambos comparten (socio, origen, componente) en el mismo flush
        // y violan uniq_partner_from_basket. Igual que hace cancel() en el mover-entera.
        $this->em->flush();
    }

    /**
     * Crea un intent SIN destino: "no recoge" (component null) o "quitar un
     * componente" (component != null) una semana. Pensado para semanas aún SIN
     * generar: el generador y el proyector lo leen y lo aplican al materializar; aquí
     * no hay WeeklyBasket que tocar. En semanas YA generadas la pantalla usa el camino
     * directo (estado del WB / WeeklyBasketItem), no este intent.
     *
     * @param Partner              $partner
     * @param Basket               $basket    Semana sobre la que actuar.
     * @param BasketComponent|null $component null = toda la entrega (no recoge); si no, el componente a quitar.
     * @param string|null          $actor
     * @return PartnerDeliveryShift
     */
    public function applySkipIntent(Partner $partner, Basket $basket, ?BasketComponent $component, ?string $actor = null): PartnerDeliveryShift
    {
        $shift = new PartnerDeliveryShift($partner, $basket, null, $component);
        $this->em->persist($shift);

        // "No recoge" de la entrega ENTERA (component null): LIBERA el día. Si la semana
        // está GENERADA y el socio tiene un WeeklyBasket materializado, se RETIRA (con sus
        // líneas) en vez de dejarlo clavado en estado "no recoge" (status 2). Así el día
        // queda LIBRE como destino de otra cesta y la entrega aparcada vive solo en el
        // intent (recuperable). Reparto y PDF no se ven afectados: leen status 1, y un WB
        // retirado queda fuera igual que uno saltado. El "quitar un componente"
        // (component != null) NO retira el WB — eso lo gestiona el editor de componentes.
        if ($component === null) {
            $existing = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket, 'partner' => $partner]);
            if ($existing !== null) {
                $this->removeItemsOf($existing);
                $this->em->remove($existing);
            }
        }

        $this->recordEvent($partner, $basket, $basket, $actor, cancelled: false);
        $this->em->flush();

        return $shift;
    }

    /**
     * "No recoge" una entrega que VINO MOVIDA a este día (tiene un cambio entrante O→X).
     * En vez de crear un skip nuevo, RE-APUNTA ese mismo cambio a "no recoge": O→X pasa a
     * O→null. La cesta deja de entregarse en X (se libera el WB de X) y queda aparcada,
     * recuperable desde la papelera — simétrico a recoverSkipToDay. El from se conserva en
     * el ORIGEN de patrón (O), que es donde el generador la materializaría: así el skip
     * excluye el candidato correcto y la cesta no reaparece.
     *
     * @param PartnerDeliveryShift $incoming Cambio entrante (to != null) a aparcar.
     * @param string|null          $actor
     */
    public function skipMovedDelivery(PartnerDeliveryShift $incoming, ?string $actor = null): void
    {
        $partner = $incoming->getPartner();
        $from = $incoming->getFromBasket();
        $to = $incoming->getToBasket();

        // Liberar de WeeklyBasket TODO el rastro de la cesta: el destino (donde estaba) y el
        // origen (que el move dejó en status "no recoge" como marca). Tras aparcarla, la cesta
        // vive SOLO en el intent (papelera) y ningún día tiene WB suyo — si no, el proyector
        // pintaría dos veces el "no recoge" (el slot sintético + el WB saltado del origen).
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        foreach ([$to, $from] as $basket) {
            if ($basket === null) {
                continue;
            }
            $wb = $wbRepo->findOneBy(['basket' => $basket, 'partner' => $partner]);
            if ($wb !== null) {
                $this->removeItemsOf($wb);
                $this->em->remove($wb);
            }
        }

        $incoming->setToBasket(null);
        $this->recordEvent($partner, $from, $from, $actor, cancelled: false);
        $this->em->flush();
    }

    /**
     * Cancela un intent sin destino (skip / quitar componente): el componente —o toda
     * la entrega— vuelve a su patrón. Solo borra la fila del intent. Contraparte de
     * applySkipIntent. OJO: en una semana GENERADA, applySkipIntent retiró el
     * WeeklyBasket para liberar el día, así que recuperar la entrega al listado exige
     * RE-MATERIALIZARLA — eso lo hace el caller (tiene el generador), no este método.
     */
    public function cancelSkipIntent(PartnerDeliveryShift $shift, ?string $actor = null): void
    {
        $from = $shift->getFromBasket();
        $partner = $shift->getPartner();
        $this->em->remove($shift);
        $this->recordEvent($partner, $from, $from, $actor, cancelled: true);
        $this->em->flush();
    }

    /**
     * Recupera una entrega aparcada ("no recoge") COLOCÁNDOLA en un día elegido. Si $target
     * es OTRO día, re-apunta el propio intent de skip (to=null → to=target): NO toca el WB
     * del día ORIGEN — que puede pertenecer a OTRA cesta movida allí (p. ej. saltar el 12 y
     * mover el 26 encima del 12), por eso NO se usa move()/apply(). Si $target ES el origen,
     * borra el intent (la entrega vuelve a su sitio).
     *
     * Conserva la NATURALEZA de la cesta aparcada: $onlyEgg indica si en su semana ORIGEN
     * era una entrega de SOLO-HUEVO (socio con huevo más frecuente que la cesta — SANTOS).
     * En ese caso el destino se materializa como solo-huevo, NO como cesta de verdura, aunque
     * la semana destino sí lleve verdura por patrón. El caller lo decide (sabe, vía los
     * candidatos del generador, si el origen era semana de cesta), porque este servicio no
     * puede depender del generador (sería circular).
     *
     * @param PartnerDeliveryShift $skip    Intent de "no recoge" (to == null) a recuperar.
     * @param Basket               $target  Día elegido donde colocar la cesta.
     * @param bool                 $onlyEgg true si la cesta aparcada era de solo-huevo.
     * @param string|null          $actor
     */
    public function recoverSkipToDay(PartnerDeliveryShift $skip, Basket $target, bool $onlyEgg, ?string $actor = null): void
    {
        $partner = $skip->getPartner();
        $from = $skip->getFromBasket();
        $backHome = $target->getId() === $from->getId();

        if ($backHome) {
            $this->em->remove($skip); // vuelve a su día: el "no recoge" desaparece
        } else {
            $skip->setToBasket($target); // a otro día: el intent pasa de "no recoge" a "entregar en target"
        }
        $this->em->flush();

        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        // Listado del destino sin generar: basta el intent (lo leerá el generador). En
        // backHome el skip se borró → el generador recreará la entrega de patrón.
        if ($wbRepo->findOneBy(['basket' => $target]) === null) {
            $this->recordEvent($partner, $from, $target, $actor, cancelled: $backHome);
            $this->em->flush();

            return;
        }

        $wb = $wbRepo->findOneBy(['basket' => $target, 'partner' => $partner]);
        if ($wb !== null) {
            $this->removeItemsOf($wb);
        } else {
            $wb = (new WeeklyBasket())->setBasket($target)->setPartner($partner);
            $this->em->persist($wb);
        }
        $this->configureAsShiftDestination($wb, $partner, $target);
        if ($onlyEgg) {
            $wb->setBasketShare($this->em->getRepository(BasketShare::class)->find(BasketShare::ID_ONLY_EGG));
            $wb->setAmount(0);
        }
        $this->em->flush(); // que compose() vea el WB sin líneas y con su modalidad ya fijada

        $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $target->getDate());
        if ($share !== null) {
            $this->composer->compose($wb, $share, $target, eggReferenceBasket: $from);
        }

        $this->recordEvent($partner, $from, $target, $actor, cancelled: $backHome);
        $this->em->flush();
    }

    /**
     * Re-apunta el destino de un cambio puntual a otra semana, conservando su
     * origen. Entrada PÚBLICA de {@see repoint} para la reconciliación al cerrar
     * una semana: cuando se cierra la semana DESTINO de un cambio, la cesta se
     * arrastra a la siguiente operativa (como si esa semana le hubiera tocado
     * naturalmente). La composición se deriva del patrón con los huevos del
     * origen como referencia (carryItems=null → stampDestination la deriva).
     *
     * @param string|null $actor Quien origina el re-apuntado.
     */
    public function repointTarget(PartnerDeliveryShift $shift, Basket $newTarget, ?string $actor = null): void
    {
        $this->repoint($shift, $newTarget, null, $actor);
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
