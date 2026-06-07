<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Repository\BasketRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketRepository;

/**
 * Reconcilia los cambios puntuales de socio (PartnerDeliveryShift) que tocan una
 * semana que se CIERRA (cancelación global: esa semana no reparte nadie). El
 * cierre y los shifts son mecanismos desconectados; sin esto, al cerrar una
 * semana los cambios registrados quedan colgando (entregas huérfanas o cestas
 * que caen al vacío).
 *
 * Reglas (decididas en docs/redesign/reparto-materializacion-tardia.md):
 *  - Shift que SALE de la semana cerrada (from = W):
 *      · "no recoge" (to = null): redundante con el cierre → se limpia.
 *      · mover (to ≠ W) con destino YA repartido/congelado: NO se toca (la cesta
 *        ya está consumida; anularla daría doble entrega — caso "recojo antes").
 *      · mover con destino aún en dibujo (no materializado): se ANULA; el socio
 *        cae al desplazamiento natural del cierre, que la proyección ya calcula.
 *        Queda pendiente avisarle (mail = deuda).
 *  - Shift que ENTRA a la semana cerrada (to = W): se RE-APUNTA a la siguiente
 *    semana operativa del nodo, como si esa semana le hubiera tocado de forma
 *    natural y el cierre la desplazara.
 *
 * Solo entrega ENTERA: los intents POR COMPONENTE (mover solo la verdura) quedan
 * fuera de alcance por ahora (mismo perímetro que la proyección).
 *
 * Idempotente: tras reconciliar, ya no quedan shifts tocando W (los de salida se
 * anularon, los de entrada cambiaron de destino), así que re-ejecutarla no hace nada.
 */
class ClosureShiftReconciler
{
    /** Actor de los eventos que genera la reconciliación (no hay gestor humano detrás). */
    private const ACTOR = 'cierre-semana';

    /** Tope de viernes a mirar hacia delante buscando la siguiente operativa del nodo. */
    private const MAX_LOOKAHEAD = 12;

    public function __construct(
        private readonly PartnerDeliveryShiftRepository $shiftRepo,
        private readonly WeeklyBasketRepository $weeklyBasketRepo,
        private readonly BasketRepository $basketRepo,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly ShiftReconciliationActions $applier,
    ) {
    }

    /**
     * Reconcilia los shifts que tocan la semana del cierre dado. No hace nada si
     * la excepción no es un cierre GLOBAL (node = null y sin fecha trasladada).
     *
     * @param DeliveryException $closure Excepción recién guardada.
     * @return array{cancelled:int, repointed:int, kept:int, notify:int[]} Resumen:
     *         shifts anulados, re-apuntados, dejados intactos, y los partner ids a
     *         los que habría que avisar (deuda de mail).
     */
    public function reconcile(DeliveryException $closure): array
    {
        $cancelled = 0;
        $repointed = 0;
        $kept = 0;
        $notify = [];

        if ($closure->getNode() !== null || !$closure->isCancelled()) {
            return ['cancelled' => 0, 'repointed' => 0, 'kept' => 0, 'notify' => []];
        }
        $week = $closure->getBasket();
        if ($week === null) {
            return ['cancelled' => 0, 'repointed' => 0, 'kept' => 0, 'notify' => []];
        }

        // SALIENTES (from = W): el socio nacía esta semana y la movió/saltó.
        foreach ($this->shiftRepo->findAllOutgoingFromBasket($week) as $shift) {
            if (!$shift->isWholeDelivery()) {
                continue; // intents por componente: fuera de alcance
            }
            $to = $shift->getToBasket();
            if ($to === null) {
                // "no recoge": el cierre ya garantiza que no recoge → limpiar el intent.
                $this->applier->cancelSkipIntent($shift, self::ACTOR);
                $cancelled++;
                continue;
            }
            if ($this->alreadyDelivered($to, $shift->getPartner())) {
                // Destino ya repartido/congelado: la cesta está consumida. Anular daría
                // doble entrega → se deja intacto.
                $kept++;
                continue;
            }
            $this->applier->cancel($shift, self::ACTOR);
            $cancelled++;
            $notify[] = $shift->getPartner()->getId();
        }

        // ENTRANTES (to = W): el socio movió su cesta HACIA la semana que se cierra.
        foreach ($this->shiftRepo->findAllIncomingToBasket($week) as $shift) {
            if (!$shift->isWholeDelivery()) {
                continue;
            }
            $next = $this->nextOperativeAfter($week, $shift->getPartner());
            if ($next === null) {
                // Sin siguiente operativa a la vista: anular y dejar al patrón natural.
                $this->applier->cancel($shift, self::ACTOR);
                $cancelled++;
                $notify[] = $shift->getPartner()->getId();
                continue;
            }
            $this->applier->repointTarget($shift, $next, self::ACTOR);
            $repointed++;
        }

        return [
            'cancelled' => $cancelled,
            'repointed' => $repointed,
            'kept' => $kept,
            'notify' => array_values(array_unique($notify)),
        ];
    }

    /**
     * ¿La entrega destino ya está materializada (congelada/repartida)? Es el guard
     * de doble entrega: si existe WeeklyBasket para (destino, socio), la cesta ya
     * está comprometida y no se toca.
     */
    private function alreadyDelivered(Basket $to, Partner $partner): bool
    {
        return $this->weeklyBasketRepo->findOneBy(['basket' => $to, 'partner' => $partner]) !== null;
    }

    /**
     * Primera semana operativa del nodo del socio DESPUÉS de la semana cerrada:
     * el siguiente Basket en el que su nodo reparte (descontando cadencia y
     * excepciones vía {@see NodeDeliveryDate}). Null si no hay ninguna a la vista
     * (dentro del tope de búsqueda) o el socio no tiene nodo.
     */
    private function nextOperativeAfter(Basket $week, Partner $partner): ?Basket
    {
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        $cursor = $week;
        for ($i = 0; $i < self::MAX_LOOKAHEAD; $i++) {
            $cursor = $this->basketRepo->findNextAfter($cursor);
            if ($cursor === null) {
                return null;
            }
            if ($node === null) {
                return $cursor; // legacy sin nodo: el siguiente viernes sin más
            }
            if ($this->nodeDeliveryDate->physicalDateFor($cursor, $node) !== null) {
                return $cursor;
            }
        }

        return null;
    }
}
