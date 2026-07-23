<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
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
        private readonly NodeDeliveryDate $nodeDeliveryDate,
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

        // Si el sel pedido no cae en el mes visible pero SÍ en la rejilla extendida (una
        // entrega recién movida a la semana del mes vecino que ya se dibuja), se selecciona
        // desde ahí: tras mover cruzando de mes el panel muestra la entrega en su nuevo día
        // SIN sacarte del mes que estás viendo. gridSlots ya trae los meses vecinos y está
        // filtrado de saltadas, así que una entrega real movida está presente.
        if ($selectedBasketId > 0 && ($selected === null || $selected['basket']->getId() !== $selectedBasketId)) {
            foreach ($gridSlots as $gridSlot) {
                if ($gridSlot['basket']->getId() === $selectedBasketId) {
                    $selected = $gridSlot;
                    break;
                }
            }
        }

        // Una entrega se gestiona (mover / saltar / editar) hasta el DÍA ANTERIOR a su
        // recogida: si se recoge hoy o ya pasó, queda en solo lectura. De ahí el `>`
        // estricto sobre la fecha física de recogida (no `>=`).
        $today = new \DateTimeImmutable('today');
        $selectedEditable = $selected !== null && $selected['date'] > $today;

        // Horizonte de la rejilla (de qué lunes a qué domingo se dibuja) y de los destinos de
        // arrastre: las semanas naturales del mes extendidas para dejar a la vista el reparto
        // del NODO inmediatamente anterior y posterior al mes (el hueco donde adelantar/posponer
        // el reparto del borde). Se ancla al NODO —no al próximo reparto del socio, que si es
        // quincenal se salta semanas— para que funcione igual en Torremocha (semanal) y en los
        // nodos de Madrid (quincenales). Ver gridHorizon().
        [$gridStart, $gridEnd] = $this->gridHorizon($partner->getWeeklyBasketGroup()?->getNode(), $current);

        // Destinos de ARRASTRE (mover una entrega a otro día): días del mes donde el nodo
        // reparte y el socio NO tiene ya una entrega real — modelo relajado (cualquier día
        // libre, incluido un "no recoge", vale; mover ahí lo revive). Map fecha física
        // 'Y-m-d' → basketId, para que la rejilla marque esas celdas como soltables. El
        // endpoint de mover revalida; esto es solo para resaltar y permitir el drop. No se
        // ofrece en compartidas (R1) ni en solo lectura ($withDropTargets = false).
        // Destinos de ARRASTRE, en el MISMO horizonte que la rejilla ($gridStart..$gridEnd) para
        // que solo se ofrezcan días soltables realmente a la vista. Dos variantes según el socio:
        //  - NO compartida: mover la entrega ENTERA a un día libre del nodo ($dropDays).
        //  - Compartida (R1): la cesta no se mueve, pero los HUEVOS sí (cada hogar los suyos)
        //    → destinos donde el nodo reparte y el socio NO recoge ya huevos ($eggDropDays).
        // El endpoint de mover revalida; esto es solo para resaltar y permitir el drop.
        $dropDays = [];
        $eggDropDays = [];
        if ($withDropTargets) {
            $activeShare = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $current);
            if ($activeShare !== null) {
                $monthBaskets = $this->em->getRepository(Basket::class)->createQueryBuilder('b')
                    ->where('b.date BETWEEN :start AND :end')
                    ->setParameter('start', $gridStart->format('Y-m-d'))
                    ->setParameter('end', $gridEnd->format('Y-m-d'))
                    ->orderBy('b.date', 'ASC')
                    ->getQuery()
                    ->getResult();

                if ($partner->getSharePartner() === null) {
                    // Ocupado = día con entrega real (un hueco por día).
                    $occupied = [];
                    foreach ($slots as $s) {
                        if (!empty($s['items'])) {
                            $occupied[$s['basket']->getId()] = true;
                        }
                    }
                    $dropDays = $this->dropTargets($monthBaskets, $activeShare, $occupied, $today);
                } elseif ($activeShare->getEggAmount() !== null) {
                    // Compartida con huevos: destinos de HUEVOS. Ocupado = día que YA lleva huevos.
                    $occupied = [];
                    foreach ($slots as $s) {
                        foreach ($s['items'] as $line) {
                            if ($line['component']->getId() === BasketComponent::ID_EGGS) {
                                $occupied[$s['basket']->getId()] = true;
                            }
                        }
                    }
                    $eggDropDays = $this->dropTargets($monthBaskets, $activeShare, $occupied, $today);
                }
            }
        }

        // "Recoge en X": X es el NODO de reparto (Torremocha, Madrid…), no el grupo
        // de recogida (Pedrezuela…). Fallback al grupo si un dato legacy no tiene nodo.
        $group = $partner->getWeeklyBasketGroup();
        $pickupPlace = $group?->getNode()?->getName() ?? $group?->getName();

        // Lista ordenada de destinos para el selector de "mover huevos" del panel (compartidas):
        // los mismos $eggDropDays (fecha física → basketId) como pares {basketId, date} para pintar.
        $eggDropTargets = [];
        foreach ($eggDropDays as $ymd => $targetBasketId) {
            $eggDropTargets[] = ['basketId' => $targetBasketId, 'date' => new \DateTimeImmutable($ymd)];
        }

        return [
            'partner' => $partner,
            'group' => $group,
            'pickup_place' => $pickupPlace,
            'slots' => $slots,
            'tray' => $tray,
            'weeks' => $this->buildMonthWeeks($year, $month, $gridStart, $gridEnd, $gridSlots, $dropDays),
            'selected' => $selected,
            'selected_editable' => $selectedEditable,
            // ¿Hay días libres a los que mover una entrega? Un socio semanal recoge todas
            // las semanas → sin destino posible; sirve para ocultar el "Mover a otra fecha".
            'has_drop_targets' => $dropDays !== [],
            // Compartidas: ¿hay días a los que mover SOLO los huevos? (la cesta no se mueve, R1).
            'has_egg_drop_targets' => $eggDropDays !== [],
            'egg_drop_targets' => $eggDropTargets,
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
     * Días destino de arrastre: de $monthBaskets, los que el nodo del socio reparte, no están
     * ya $occupied y son futuros (mismo deadline del día anterior a la recogida). Map fecha
     * física 'Y-m-d' → basketId. El predicado de "ocupado" lo pone el llamante: entrega entera
     * (cualquier ítem) para no-compartidas, o presencia de huevos para el mover-huevos de las
     * compartidas.
     *
     * @param Basket[]         $monthBaskets Baskets del horizonte de la rejilla.
     * @param array<int, true> $occupied     basketId ya ocupado (no ofrecer como destino).
     * @return array<string, int> Fecha física 'Y-m-d' → basketId destino.
     */
    private function dropTargets(array $monthBaskets, PartnerBasketShare $share, array $occupied, \DateTimeImmutable $today): array
    {
        $days = [];
        foreach ($monthBaskets as $b) {
            if (isset($occupied[$b->getId()])) {
                continue;
            }
            $projection = $this->generator->projectShareDelivery($b, $share);
            if ($projection === null) {
                continue; // el nodo no reparte esa semana
            }
            $physical = $projection['deliveryDate'] ?? $b->getDate();
            if ($physical <= $today) {
                continue; // mismo deadline: destino futuro
            }
            $days[$physical->format('Y-m-d')] = $b->getId();
        }

        return $days;
    }

    /**
     * Construye la rejilla del calendario (semanas de lunes a domingo, de $gridStart a
     * $gridEnd) que pinta la pantalla, colocando cada entrega proyectada en la celda de su
     * fecha física. Marca como del mes (`inMonth`) las celdas del mes mostrado; el resto son
     * días de un mes vecino que la rejilla desborda para dejar a la vista el reparto adyacente.
     *
     * @param int                                                        $year
     * @param int                                                        $month
     * @param \DateTimeImmutable                                         $gridStart Lunes en que arranca la rejilla.
     * @param \DateTimeImmutable                                         $gridEnd   Domingo en que termina la rejilla.
     * @param list<array{date: \DateTimeInterface, basket: Basket, ...}> $slots
     * @param array<string, int>                                         $dropDays Fecha física 'Y-m-d' → basketId destino de arrastre (día libre del nodo).
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, slot: array|null, dropBasketId: int|null}>>
     */
    private function buildMonthWeeks(int $year, int $month, \DateTimeImmutable $gridStart, \DateTimeImmutable $gridEnd, array $slots, array $dropDays = []): array
    {
        // Mapa fecha física (Y-m-d) → slot, para colocar la entrega en su celda.
        $byDay = [];
        foreach ($slots as $slot) {
            $byDay[$slot['date']->format('Y-m-d')] = $slot;
        }

        $monthYm = sprintf('%04d-%02d', $year, $month);
        $cursor = $gridStart;

        $weeks = [];
        do {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $cursor->format('Y-m') === $monthYm,
                    'slot' => $byDay[$cursor->format('Y-m-d')] ?? null,
                    'dropBasketId' => $dropDays[$cursor->format('Y-m-d')] ?? null,
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        } while ($cursor <= $gridEnd);

        return $weeks;
    }

    /**
     * Horizonte de la rejilla: de qué lunes a qué domingo se dibuja.
     *
     * Parte de las semanas NATURALES del mes (lunes en/antes del día 1 → domingo en/tras el
     * último día) y las extiende lo justo para dejar a la vista el reparto del NODO
     * inmediatamente ANTERIOR al mes y el inmediatamente POSTERIOR: el hueco donde adelantar
     * o posponer el reparto del borde. Si ese reparto vecino ya cae en las semanas naturales
     * (p. ej. nodo semanal y mes que empieza en sábado), no se extiende por ese lado.
     *
     * Se ancla al NODO (su día de la semana y su cadencia, vía {@see NodeDeliveryDate}), no al
     * próximo reparto del socio: un socio quincenal salta semanas y desplazaría el borde. Así
     * vale igual para Torremocha (semanal) y los nodos de Madrid (quincenales); en un nodo
     * quincenal el reparto vecino puede quedar a dos semanas y la extensión es acorde.
     *
     * Sin nodo (dato legacy) o sin reparto vecino localizable, se queda en las semanas naturales.
     *
     * @param Node|null          $node    Nodo del socio (define día de reparto y cadencia).
     * @param \DateTimeImmutable $current Día 1 del mes mostrado.
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [lunesInicio, domingoFin]
     */
    private function gridHorizon(?Node $node, \DateTimeImmutable $current): array
    {
        $monthEnd = $current->modify('last day of this month');
        // Semanas naturales del mes (lunes-domingo que lo cubren). N: 1=lunes .. 7=domingo.
        $naturalStart = $current->modify('-' . ((int) $current->format('N') - 1) . ' days');
        $naturalEnd = $monthEnd->modify('+' . (7 - (int) $monthEnd->format('N')) . ' days');

        if ($node === null) {
            return [$naturalStart, $naturalEnd];
        }

        // Repartos físicos del NODO en una ventana holgada alrededor del mes (±3 semanas cubre
        // de sobra la cadencia quincenal). physicalDateFor mapea al día del nodo y aplica
        // cadencia/excepciones; null = ese nodo no reparte esa semana.
        $windowStart = $naturalStart->modify('-21 days');
        $windowEnd = $naturalEnd->modify('+21 days');
        /** @var Basket[] $baskets */
        $baskets = $this->em->getRepository(Basket::class)->createQueryBuilder('b')
            ->where('b.date BETWEEN :start AND :end')
            ->setParameter('start', $windowStart->format('Y-m-d'))
            ->setParameter('end', $windowEnd->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        // Reparto del nodo inmediatamente anterior al mes y el inmediatamente posterior.
        $prev = null;
        $next = null;
        foreach ($baskets as $basket) {
            $physical = $this->nodeDeliveryDate->physicalDateFor($basket, $node);
            if ($physical === null) {
                continue;
            }
            if ($physical < $current) {
                if ($prev === null || $physical > $prev) {
                    $prev = $physical;
                }
            } elseif ($physical > $monthEnd) {
                if ($next === null || $physical < $next) {
                    $next = $physical;
                }
            }
        }

        // Se extiende COMO MUCHO una semana por lado, y solo si el reparto del nodo vecino
        // cae justo en esa semana inmediata (y no ya en las naturales). Así un nodo semanal
        // enseña su reparto vecino; uno quincenal, cuyo reparto vecino queda a dos semanas, no
        // extiende (mostrarlo colaría una semana vacía y quedaría fatal): se deja en lo natural.
        $weekBack = $naturalStart->modify('-7 days');
        $gridStart = ($prev !== null && $prev >= $weekBack && $prev < $naturalStart) ? $weekBack : $naturalStart;
        $weekForward = $naturalEnd->modify('+7 days');
        $gridEnd = ($next !== null && $next > $naturalEnd && $next <= $weekForward) ? $weekForward : $naturalEnd;

        return [$gridStart, $gridEnd];
    }
}
