<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerDeliveryShiftRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traslada la cesta de un socio a una semana en la que YA recoge, ACUMULANDO: el
 * día destino pasa a llevar 2 cestas y el de origen se queda sin entrega. Es el
 * gesto que la gestión hacía a mano en dos pasos (saltar el origen + añadir una
 * cesta extra al destino), fundido en uno.
 *
 * NO se modela como un cambio de día con destino: un día no puede llevar dos
 * WeeklyBasket del mismo socio, así que la segunda cesta vive como cesta extra
 * ({@see ExtraBasketEditor::addToDelivery}, que SUMA sobre lo que ya lleva) y el origen
 * se vacía con un intent sin destino ({@see DeliveryShiftApplier::applySkipIntent}),
 * marcado con `accumulatedTo` para que no se confunda con un "no recoge" —si no, la
 * cesta se cuenta dos veces: sumada en el destino y pendiente en la papelera del origen.
 *
 * Tampoco se deshace arrastrando la cesta de vuelta (un día con 2 cestas no sabe cuál
 * de las dos vino trasladada): el camino de vuelta es {@see self::undo()}, que devuelve
 * a su semana todas las cestas trasladadas a un día y retira el añadido.
 *
 * Orden deliberado: primero SUMA al destino y después vacía el origen; como red
 * adicional, el llamante lo envuelve en una transacción ({@see PartnerDeliveryCalendarController})
 * para que un fallo intermedio haga rollback de todo. Aun sin transacción, el orden
 * garantiza que el peor caso sea una cesta de MÁS (visible), nunca una de menos.
 *
 * Cadenas de acumulación (acumular sobre un día ya acumulado): la composición trasladada se
 * lee de la proyección, así que incluye el extra que el origen ya tenía, y vaciar el origen
 * borra su {@see \App\Entity\PartnerBasketExtra} —lo hace {@see DeliveryShiftApplier::applySkipIntent},
 * que deja el día a cero—. Antes ese override sobrevivía y la proyección lo resucitaba,
 * duplicando la cesta.
 */
final class AccumulatingMove
{
    public function __construct(
        private readonly PartnerDeliverySkipper $applier,
        private readonly ExtraBasketAdder $extraEditor,
        private readonly ExtraBasketRemover $extraRemover,
        private readonly PartnerMonthProjection $projector,
        private readonly PartnerDeliveryShiftRepository $shiftRepository,
        private readonly WeeklyBasketGenerator $generator,
        private readonly EntityManagerInterface $em,
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
        //    El intent se marca con `accumulatedTo` en el MISMO flush que lo crea: así el día
        //    origen queda libre pero su cesta NO cuenta como pendiente en la papelera (estaría
        //    contada dos veces, y la tarjeta invitaría a recuperar una cesta ya colocada).
        $incoming = $this->shiftRepository->findIncoming($partner, $from);
        if ($incoming !== null) {
            $this->applier->skipMovedDelivery($incoming, $actor, $to);
        } else {
            $this->applier->applySkipIntent($partner, $from, null, $actor, $to);
        }
    }

    /**
     * Deshace los traslados sumando que dejaron cestas en la entrega de $to: cada cesta
     * vuelve a su semana de origen y el añadido de $to desaparece. Es el camino de vuelta
     * de {@see self::move()}, en un solo gesto.
     *
     * Deshace TODOS los traslados que apuntan a esa semana, no uno: el añadido es una
     * cantidad acumulada en una fila por componente ({@see ExtraBasketEditor}), así que no
     * hay forma de retirar "solo la cesta del día 4" de un día que recibió dos. Igual que
     * "Quitar la cesta extra", es todo-o-nada — y por el mismo motivo se lleva por delante
     * una posible extra GENUINA de ese día (añadida a mano por gestión), que habría que
     * volver a poner. La UI lo advierte.
     *
     * Orden deliberado, el inverso al de move(): primero se devuelven las cestas a su
     * semana y solo después se quita el añadido del destino. Así un fallo intermedio deja
     * una cesta de MÁS (visible en el listado), nunca una de menos.
     *
     * @param Partner     $partner Socio.
     * @param Basket      $to      Semana que recibió las cestas sumadas.
     * @param string|null $actor   Quién lo origina (ver PartnerEvent::$actor).
     * @return int Cuántas cestas han vuelto a su semana.
     * @throws \LogicException si esa semana no recibió ninguna cesta trasladada.
     */
    public function undo(Partner $partner, Basket $to, ?string $actor = null): int
    {
        $shifts = $this->shiftRepository->findAccumulatedInto($partner, $to);
        if ($shifts === []) {
            throw new \LogicException('Esa semana no tiene ninguna cesta trasladada que devolver.');
        }

        // 1. Cada cesta vuelve a su semana: borrar el intent la devuelve al patrón, y si esa
        //    semana ya está generada hay que re-materializar su entrega para que reaparezca
        //    en el listado de reparto (cancelSkipIntent no toca la piedra, a propósito).
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        foreach ($shifts as $shift) {
            $origin = $shift->getFromBasket();
            $this->applier->cancelSkipIntent($shift, $actor);

            if ($origin === null || $wbRepo->findOneBy(['basket' => $origin]) === null) {
                continue; // semana sin generar: basta el patrón, la proyección ya la dibuja.
            }
            $share = $shareRepo->findActiveForPartner($partner, $origin->getDate());
            if ($share !== null) {
                $this->generator->materializeShareDelivery($origin, $share);
                $this->em->flush();
            }
        }

        // 2. Quitar el añadido del destino: vuelve a llevar solo lo que le toca por patrón.
        $this->extraRemover->removeExtra($partner, $to, $actor);

        return count($shifts);
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
