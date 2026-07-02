<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Repository\PartnerDeliveryShiftRepository;

/**
 * Traslada la cesta de un socio a una semana en la que YA recoge, ACUMULANDO: el
 * día destino pasa a llevar 2 cestas y el de origen se queda sin entrega. Es el
 * gesto que la gestión hacía a mano en dos pasos (saltar el origen + añadir una
 * cesta extra al destino), fundido en uno.
 *
 * NO es un {@see PartnerDeliveryShift} (traslado reversible con "deshacer"): un día
 * con 2 cestas no sabe cuál de las dos vino movida, así que no se puede arrastrar de
 * vuelta. Se modela con las piezas ya probadas: "no recoge" en el origen
 * ({@see DeliveryShiftApplier::applySkipIntent}) + cesta extra en el destino
 * ({@see ExtraBasketEditor::addToDelivery}, que SUMA sobre lo que ya lleva).
 *
 * Orden deliberado: primero SUMA al destino y después vacía el origen; como red
 * adicional, el llamante lo envuelve en una transacción ({@see PartnerDeliveryCalendarController})
 * para que un fallo intermedio haga rollback de todo. Aun sin transacción, el orden
 * garantiza que el peor caso sea una cesta de MÁS (visible), nunca una de menos.
 *
 * DEUDA conocida: si el ORIGEN era a su vez un destino de "trasladar sumando" (tiene un
 * {@see \App\Entity\PartnerBasketExtra} colgado), vaciarlo aquí retira su WeeklyBasket pero
 * NO borra ese override, que la proyección resucitaría. Solo afecta a cadenas de acumulación
 * (acumular sobre un día ya acumulado); el caso normal (origen = cesta de patrón) no lo toca.
 */
final class AccumulatingMove
{
    public function __construct(
        private readonly DeliveryShiftApplier $applier,
        private readonly ExtraBasketEditor $extraEditor,
        private readonly DeliveryCalendarProjector $projector,
        private readonly PartnerDeliveryShiftRepository $shiftRepository,
    ) {
    }

    /**
     * Traslada la entrega del socio de $from a $to sumándola a la que $to ya tiene.
     *
     * @param Partner     $partner Socio.
     * @param Basket      $from    Semana de origen (se queda sin entrega).
     * @param Basket      $to      Semana destino (ya recoge; pasa a llevar 2 cestas).
     * @param string|null $actor   Quién lo origina (ver PartnerEvent::$actor).
     *
     * @throws \LogicException si el origen no lleva nada que trasladar.
     */
    public function move(Partner $partner, Basket $from, Basket $to, ?string $actor = null): void
    {
        // Lo que se traslada = lo que lleva el origen (verdura y/o huevos), leído ANTES
        // de vaciarlo. La proyección unifica piedra y dibujo, así que funciona tanto si
        // la semana de origen está generada como si solo está prevista.
        $addAmounts = $this->originItems($partner, $from);
        if ($addAmounts === []) {
            throw new \LogicException('La cesta de origen no lleva nada que trasladar.');
        }

        // 1. SUMAR al destino (crea/acumula la cesta extra sobre la que ya recoge).
        $note = 'Trasladada desde el ' . ($from->getDate()?->format('d/m/Y') ?? '?');
        $this->extraEditor->addToDelivery($partner, $to, $addAmounts, $note, $actor);

        // 2. Vaciar el origen ("no recoge" esa semana). Si la cesta llegó a $from por un cambio
        //    previo (move/recover), $from es el DESTINO de un shift ENTRANTE: hay que RE-APUNTAR
        //    ese shift a "no recoge" (skipMovedDelivery), no crear un skip nuevo, o el día quedaría
        //    con dos estados a la vez (aparcado en papelera Y proyectado como entrega activa).
        $incoming = $this->shiftRepository->findIncoming($partner, $from);
        if ($incoming !== null) {
            $this->applier->skipMovedDelivery($incoming, $actor);
        } else {
            $this->applier->applySkipIntent($partner, $from, null, $actor);
        }
    }

    /**
     * Composición del origen como incrementos por componente, [BasketComponent id => cantidad],
     * leída de la proyección del mes (piedra o dibujo). Vacío si esa semana no lleva nada.
     *
     * @return array<int, string>
     */
    private function originItems(Partner $partner, Basket $from): array
    {
        $date = $from->getDate();
        if ($date === null) {
            return [];
        }

        $month = $this->projector->projectMonth($partner, (int) $date->format('Y'), (int) $date->format('n'));
        foreach ($month as $slot) {
            if ($slot['basket']->getId() !== $from->getId()) {
                continue;
            }
            $add = [];
            foreach ($slot['items'] as $line) {
                // Solo importes positivos: una línea a 0 no se traslada (y el guard de
                // "origen sin nada" no debe pasar por alto un origen de puros ceros).
                if ((float) $line['amount'] > 0) {
                    $add[$line['component']->getId()] = $line['amount'];
                }
            }

            return $add;
        }

        return [];
    }
}
