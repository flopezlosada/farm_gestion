<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Construye el modelo de vista del calendario de recogida de un socio (la rejilla
 * mensual, la papelera, el día seleccionado y, opcionalmente, los destinos de
 * arrastre). Lo comparten la pantalla del gestor (PartnerController) y la del socio
 * (PanelController) para no duplicar la lógica de proyección.
 *
 * No decide permisos ni rutas: solo arma los datos que pinta el partial
 * `templates/partner/_calendar.html.twig`.
 */
final class DeliveryCalendarViewBuilder
{
    public function __construct(
        private readonly DeliveryCalendarProjector $projector,
        private readonly WeeklyBasketGenerator $generator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Arma el modelo de vista del calendario para un mes.
     *
     * @param Partner  $partner          Socio dueño del calendario (en familias, el principal).
     * @param int|null $year             Año pedido; null junto a $month null = mes de la próxima entrega.
     * @param int|null $month            Mes pedido (1-12); null = abrir en el mes de la próxima entrega.
     * @param int      $selectedBasketId Basket a mostrar en el panel (0 = el primero del mes).
     * @param bool     $withDropTargets  true = calcular los días destino de arrastre (edición); false = solo lectura.
     * @return array<string, mixed> Variables para el partial del calendario.
     */
    public function build(Partner $partner, ?int $year, ?int $month, int $selectedBasketId, bool $withDropTargets): array
    {
        // Sin mes explícito (entrada desde la ficha), abrimos en el mes de la
        // PRÓXIMA entrega del socio, no en el mes actual: a fin de mes lo de este
        // mes ya no se puede gestionar. Si navega con las flechas, se respeta el
        // mes pedido.
        if ($month !== null) {
            if ($month < 1 || $month > 12) {
                $default = new \DateTimeImmutable('first day of this month');
                $year = (int) $default->format('Y');
                $month = (int) $default->format('n');
            } else {
                $year ??= (int) (new \DateTimeImmutable('first day of this month'))->format('Y');
            }
        } else {
            [$year, $month] = $this->resolveNextDeliveryMonth($partner);
        }

        $current = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $slots = $this->projector->projectMonth($partner, $year, $month);

        // Para PINTAR la rejilla (no para la lógica) hace falta también las entregas
        // de los meses lógicos vecinos cuya fecha FÍSICA cae en el mes visible: una
        // cesta de ciclo 1-oct con recogida física el 30-sep debe verse en septiembre.
        // Cada Basket vive en un único mes lógico, así que las tres proyecciones cubren
        // Baskets disjuntos (sin duplicados); buildMonthWeeks descarta los slots cuya
        // fecha física no cae en ninguna celda visible. La selección del panel y los
        // candidatos de mover siguen usando $slots (solo el mes actual).
        // El mes actual va el ÚLTIMO en la fusión: si por un dato sucio dos entregas
        // cayeran en la misma fecha física, en la rejilla (byDay, último gana) manda
        // la del mes que se está mirando, no la colada de un vecino.
        $prevMonth = $current->modify('-1 month');
        $nextMonth = $current->modify('+1 month');
        $gridSlots = array_merge(
            $this->projector->projectMonth($partner, (int) $prevMonth->format('Y'), (int) $prevMonth->format('n')),
            $this->projector->projectMonth($partner, (int) $nextMonth->format('Y'), (int) $nextMonth->format('n')),
            $slots,
        );

        // La PAPELERA: las entregas "no recoge" del mes (saltadas), aparcadas y
        // recuperables. Salen de la rejilla — su día queda LIBRE — y se muestran aparte.
        // La rejilla solo pinta lo que SÍ se recoge.
        $tray = array_values(array_filter($slots, static fn (array $s): bool => $s['skipped']));
        $gridSlots = array_filter($gridSlots, static fn (array $s): bool => !$s['skipped']);

        // Día seleccionado para el panel lateral: el Basket pedido (?sel), o por
        // defecto la primera entrega del mes. Solo entregas reales son seleccionables.
        $selected = $this->resolveSelectedSlot($slots, $selectedBasketId, $current->format('Y-m'));

        // Una entrega se gestiona (mover / saltar / editar) hasta el DÍA ANTERIOR a su
        // recogida: si se recoge hoy o ya pasó, queda en solo lectura. De ahí el `>`
        // estricto sobre la fecha física de recogida (no `>=`).
        $today = new \DateTimeImmutable('today');
        $selectedEditable = $selected !== null && $selected['date'] > $today;

        // Destinos de ARRASTRE (mover una entrega a otro día): días del mes donde el nodo
        // reparte y el socio NO tiene ya una entrega real — modelo relajado (cualquier día
        // libre, incluido un "no recoge", vale; mover ahí lo revive). Map fecha física
        // 'Y-m-d' → basketId, para que la rejilla marque esas celdas como soltables. El
        // endpoint de mover revalida; esto es solo para resaltar y permitir el drop. No se
        // ofrece en compartidas (R1) ni en solo lectura ($withDropTargets = false).
        $dropDays = [];
        if ($withDropTargets && $partner->getSharePartner() === null) {
            $activeShare = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $current);
            if ($activeShare !== null) {
                $occupiedReal = [];
                foreach ($slots as $s) {
                    if (!empty($s['items'])) {
                        $occupiedReal[$s['basket']->getId()] = true;
                    }
                }
                $monthEnd = $current->modify('last day of this month');
                $monthBaskets = $this->em->getRepository(Basket::class)->createQueryBuilder('b')
                    ->where('b.date BETWEEN :start AND :end')
                    ->setParameter('start', $current->format('Y-m-d'))
                    ->setParameter('end', $monthEnd->format('Y-m-d'))
                    ->orderBy('b.date', 'ASC')
                    ->getQuery()
                    ->getResult();
                foreach ($monthBaskets as $b) {
                    if (isset($occupiedReal[$b->getId()])) {
                        continue; // ya hay entrega real ese día: un hueco por día
                    }
                    $projection = $this->generator->projectShareDelivery($b, $activeShare);
                    if ($projection === null) {
                        continue; // el nodo no reparte esa semana
                    }
                    $physical = $projection['deliveryDate'] ?? $b->getDate();
                    if ($physical <= $today) {
                        continue; // mismo deadline: destino futuro
                    }
                    $dropDays[$physical->format('Y-m-d')] = $b->getId();
                }
            }
        }

        // "Recoge en X": X es el NODO de reparto (Torremocha, Madrid…), no el grupo
        // de recogida (Pedrezuela…). Fallback al grupo si un dato legacy no tiene nodo.
        $group = $partner->getWeeklyBasketGroup();
        $pickupPlace = $group?->getNode()?->getName() ?? $group?->getName();

        return [
            'partner' => $partner,
            'group' => $group,
            'pickup_place' => $pickupPlace,
            'slots' => $slots,
            'tray' => $tray,
            'weeks' => $this->buildMonthWeeks($year, $month, $gridSlots, $dropDays),
            'selected' => $selected,
            'selected_editable' => $selectedEditable,
            // ¿Hay días libres a los que mover una entrega? Un socio semanal recoge todas
            // las semanas → sin destino posible; sirve para ocultar el "Mover a otra fecha".
            'has_drop_targets' => $dropDays !== [],
            'current' => $current,
            'prev' => $current->modify('-1 month'),
            'next' => $current->modify('+1 month'),
        ];
    }

    /**
     * Primer mes (a partir del actual, hasta 6 vista delante) con una entrega futura
     * del socio. Si no hay ninguna, el mes actual.
     *
     * @param Partner $partner
     * @return array{0: int, 1: int} [año, mes]
     */
    private function resolveNextDeliveryMonth(Partner $partner): array
    {
        $today = new \DateTimeImmutable('today');
        $base = new \DateTimeImmutable('first day of this month');

        for ($i = 0; $i < 6; $i++) {
            $cursor = $base->modify(sprintf('+%d months', $i));
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            foreach ($this->projector->projectMonth($partner, $year, $month) as $slot) {
                if ($slot['date'] >= $today) {
                    return [$year, $month];
                }
            }
        }

        return [(int) $base->format('Y'), (int) $base->format('n')];
    }

    /**
     * Elige el slot del calendario que ocupa el panel lateral: el del Basket
     * pedido (?sel); si no, la primera entrega cuya fecha física cae DENTRO del
     * mes mostrado (no la que se cuela del mes anterior por la fecha física del
     * nodo); como último recurso, la primera del listado. Null si no hay entregas.
     *
     * @param list<array{date: \DateTimeInterface, basket: Basket, skipped: bool, ...}> $slots
     * @param int                                                                       $selectedBasketId 0 = sin preferencia.
     * @param string                                                                    $month            Mes mostrado, formato 'Y-m'.
     * @return array{basket: Basket, ...}|null
     */
    private function resolveSelectedSlot(array $slots, int $selectedBasketId, string $month): ?array
    {
        if ($slots === []) {
            return null;
        }

        if ($selectedBasketId > 0) {
            // Un día puede tener DOS slots: una cesta movida encima (rejilla) y una cesta
            // aparcada en la papelera (un "no recoge" cuyo día se reutilizó). El panel
            // gestiona la entrega REAL, así que se prefiere la NO saltada; la aparcada solo
            // como fallback (día que es únicamente "no recoge").
            $fallback = null;
            foreach ($slots as $slot) {
                if ($slot['basket']->getId() !== $selectedBasketId) {
                    continue;
                }
                if (!$slot['skipped']) {
                    return $slot;
                }
                $fallback ??= $slot;
            }
            if ($fallback !== null) {
                return $fallback;
            }
        }

        // Por defecto preferimos la PRÓXIMA entrega gestionable (fecha futura) dentro del
        // mes mostrado: así al abrir el calendario el panel ya ofrece las acciones (no
        // recoger / mover) en vez de caer en una entrega pasada en "solo lectura", que da
        // la falsa sensación de que no se puede hacer nada.
        $today = new \DateTimeImmutable('today');
        $firstInMonth = null;
        foreach ($slots as $slot) {
            if ($slot['date']->format('Y-m') !== $month) {
                continue; // descartamos las que se cuelan de un mes vecino por fecha física
            }
            $firstInMonth ??= $slot;
            if ($slot['date'] > $today) {
                return $slot;
            }
        }

        // Sin futuras este mes: la primera del mes; si ninguna cae dentro, la primera del listado.
        return $firstInMonth ?? $slots[0];
    }

    /**
     * Construye la rejilla mensual del calendario (semanas de lunes a domingo) que
     * pinta la pantalla, colocando cada entrega proyectada en la celda de su fecha
     * física. Las semanas íntegramente fuera del mes se descartan.
     *
     * @param int                                                        $year
     * @param int                                                        $month
     * @param list<array{date: \DateTimeInterface, basket: Basket, ...}> $slots
     * @param array<string, int>                                         $dropDays Fecha física 'Y-m-d' → basketId destino de arrastre (día libre del nodo).
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, slot: array|null, dropBasketId: int|null}>>
     */
    private function buildMonthWeeks(int $year, int $month, array $slots, array $dropDays = []): array
    {
        // Mapa fecha física (Y-m-d) → slot, para colocar la entrega en su celda.
        $byDay = [];
        foreach ($slots as $slot) {
            $byDay[$slot['date']->format('Y-m-d')] = $slot;
        }

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month');
        // Lunes en/antes del día 1 (N: 1=lunes .. 7=domingo).
        $cursor = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');

        $weeks = [];
        do {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $cursor->format('Y-m') === $first->format('Y-m'),
                    'slot' => $byDay[$cursor->format('Y-m-d')] ?? null,
                    'dropBasketId' => $dropDays[$cursor->format('Y-m-d')] ?? null,
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        } while ($cursor <= $last);

        return $weeks;
    }
}
