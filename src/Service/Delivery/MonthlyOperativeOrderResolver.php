<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Determina la posición operativa (1, 2, 3, …) de un Basket dentro de las
 * entregas del Node en su mes. Sirve para emparejar mensuales con su semana
 * de reparto: un mensual con day_month_order = 4 debe recoger en la 4ª
 * entrega de su nodo en el mes, independientemente del calendario.
 *
 * Dos consultas con semánticas distintas:
 *  - {@see ordersServedBy}: emparejamiento PEGAJOSO sobre el calendario base
 *    (una cancelación no desplaza a los mensuales posteriores; el afectado
 *    hace fallback). Es la que usan generador y resolver de huevos.
 *  - {@see operativeOrderForNode}: posición entre las entregas REALES
 *    (renumera). Útil como posición física pura.
 *
 * "Operativo" se delega en {@see NodeDeliveryDate::deliversInBasket}, que
 * respeta cadencia (un quincenal fuera de fase no entrega) y las
 * `DeliveryException` (cancelación o traslado) que administración registra
 * en BBDD. No hay festivos hardcoded: si admin no marca el festivo como
 * excepción, el sistema asume reparto normal.
 *
 * Sub-fase 8.8e (2026-05-28): se retira el hardcode NON_OPERATIVE_FRIDAYS y
 * los métodos isOperative/operativeOrderInMonth. Único método público:
 * operativeOrderForNode(Basket, Node).
 *
 * El mes de una entrega se determina por su FECHA FÍSICA de reparto (la que
 * devuelve {@see NodeDeliveryDate::physicalDateFor}, que aplica los traslados
 * de DeliveryException), NO por la fecha cruda del Basket. Un festivo que
 * traslada el reparto a otro mes mueve esa entrega a ese mes a efectos del
 * orden mensual. Sin esto, un mes de 5 viernes con festivo trasladado al mes
 * anterior contaba 5 entregas y desplazaba a todos los mensuales (un day=4
 * caía en el 4º de 5 en vez del último real).
 *
 * Ejemplo mayo 2026 weekly (Torremocha): los Basket viernes son [1, 8, 15,
 * 22, 29]. El 1-may está trasladado al 30-abr (delivery_exception), así que
 * su fecha física cae en ABRIL y NO cuenta en mayo. El 15-may → 14-may sigue
 * en mayo. Mayo operativo = [8, 15(→14), 22, 29] → Basket(29-may) → 4.
 * (El 3-abr, festivo sin reparto, no genera entrega.)
 *
 * Ejemplo mayo 2026 biweekly (Cascorro, anchor 6-may): entrega 6-may y
 * 20-may → posiciones 1 y 2. Para Basket(22-may), operativeOrderForNode con
 * Cascorro devuelve 2.
 *
 * ANCLAJE A UN TURNO (2026-07-30, caso Alcobendas): {@see ordersServedBy}
 * acepta un turno A/B opcional. En un nodo semanal cuyo grupo de recogida
 * reparte de hecho cada dos semanas (todos sus quincenales en el mismo turno),
 * contar los viernes del mes descoloca a los mensuales cada mes de 5 viernes,
 * porque la alternancia del turno no se reinicia con el mes. Con el turno, las
 * posiciones se cuentan sólo sobre sus semanas: "1ª del turno B" coincide
 * siempre con el grupo. Ver PartnerBasketShare::$delivery_group.
 */
class MonthlyOperativeOrderResolver
{
    /**
     * Posición máxima que puede pedir un mensual anclado a un turno. El
     * formulario ofrece hasta la 3ª entrega (más "última"); el tope da margen
     * y acota el desbordamiento que absorbe la última entrega del turno.
     */
    private const MAX_ANCHORED_ORDER = 4;

    /**
     * Posiciones que sirve la entrega de un punto MENSUAL. Un punto así abre
     * una sola vez al mes, así que su única entrega vale para cualquier
     * posición que el socio tenga en ficha: cubre todo el rango que el modelo
     * admite en `day_month_order`, positivo y negativo.
     *
     * No es redundante con forzar el dato del socio al darlo de alta: si
     * administración cambia la semana que abre el punto, los socios ya dados
     * de alta conservan la posición anterior, y sin esto desaparecerían del
     * listado sin ningún aviso — el peor fallo posible aquí.
     *
     * @var int[]
     */
    private const MONTHLY_NODE_ORDERS = [-3, -2, -1, 1, 2, 3, self::MAX_ANCHORED_ORDER];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly BiweeklyCohortResolver $biweeklyCohort,
    ) {
    }

    /**
     * Posición del Basket entre las entregas operativas del nodo en el mes,
     * apoyándose en {@see NodeDeliveryDate::deliversInBasket} (que aplica
     * cadencia y excepciones). Devuelve null si el nodo no entrega en este
     * Basket — el partner mensual de ese nodo no recoge esta semana.
     *
     * Para Cascorro biweekly (miércoles, anchor 6-may) en mayo 2026:
     * entrega en Basket(8-may) y Basket(22-may) — alternancia. Un mensual
     * con day_month_order=1 recoge en 6-may (vía Basket 8-may); con
     * day_month_order=2 recoge en 20-may (vía Basket 22-may).
     *
     * @param Basket $basket Ciclo semanal global.
     * @param Node $node Nodo donde se entrega.
     * @return int|null 1-based, o null si el nodo no entrega en este Basket.
     * @throws \RuntimeException Si el Basket no aparece entre los del mes (no debería pasar).
     */
    public function operativeOrderForNode(Basket $basket, Node $node): ?int
    {
        $physical = $this->nodeDeliveryDate->physicalDateFor($basket, $node);
        if ($physical === null) {
            return null;
        }

        $position = 0;
        foreach ($this->monthDeliveries($physical, $node) as $entry) {
            $position++;
            if ($entry['basketId'] === $basket->getId()) {
                return $position;
            }
        }

        throw new \RuntimeException(sprintf(
            'Basket id=%d (%s) no encontrado entre las entregas operativas del nodo "%s" en el mes.',
            $basket->getId(),
            $basket->getDate()->format('Y-m-d'),
            $node->getName() ?? '(sin nombre)'
        ));
    }

    /**
     * Órdenes mensuales que este Basket SIRVE para el nodo, con semántica
     * PEGAJOSA (2026-06-12, decisión de Paco): las posiciones se cuentan sobre
     * el calendario BASE del mes ({@see NodeDeliveryDate::baselineDateFor} —
     * las semanas canceladas siguen ocupando su posición), de modo que un
     * cierre NO desplaza a los mensuales de las semanas posteriores. El basket
     * sirve su propia posición base y, además, la de cualquier semana cancelada
     * cuyo FALLBACK sea él: la siguiente operativa del mes, o la última
     * operativa si la cancelada no tiene siguiente (el huevo/cesta no salta de mes).
     *
     * Ejemplo: viernes [5, 12, 19, 26], cierre del 12 → el 19 sigue siendo la
     * posición 3 y el 26 la 4; el 19 sirve además la posición 2 (fallback del
     * cancelado). Devuelve [] si el nodo no entrega en este Basket.
     *
     * A diferencia de {@see operativeOrderForNode} (posición entre las entregas
     * REALES, que renumera), esta es la consulta para EMPAREJAR mensuales
     * (day_month_order / egg_day_month_order) con su semana.
     *
     * ÍNDICE NEGATIVO — "última semana del mes" (2026-07-11, decisión de Paco):
     * además de las posiciones positivas (contadas desde el principio del mes),
     * cada posición servida se devuelve TAMBIÉN como índice negativo contado
     * desde el final: -1 = última, -2 = penúltima, etc. Así un mensual con
     * day_month_order = -1 recoge el 4º viernes en un mes de 4 y el 5º (último)
     * en un mes de 5, sin tocar consumidores: ambos hacen membership test
     * (in_array / SQL IN) contra esta lista. Motivo: el equipo de reparto
     * confirmó que el antiguo "4º viernes" siempre significó "última semana",
     * pero el modelo sólo sabía contar desde el principio (4 ≠ última en mes
     * de 5). El negativo lo hace una opción de primera clase, y la web de
     * autogestión ofrecerá "última semana" apoyándose en esto.
     *
     * Ejemplo mes de 5 viernes, posición base 5 (última): sirve +5 y -1.
     * Posición base 4: sirve +4 y -2. En mes de 4, la posición base 4 sirve
     * +4 y -1 (es la última). El fallback de semanas canceladas se hereda:
     * si la última semana se cancela, su fallback (la última operativa) sirve
     * su posición positiva y, por tanto, también el -1.
     *
     * ANCLAJE A UN TURNO — `$cohort` (2026-07-30, caso Alcobendas): en un nodo
     * de cadencia semanal, un grupo de recogida puede repartir de hecho cada
     * dos semanas porque todos sus quincenales están en el mismo turno A/B.
     * Contar los viernes del mes descoloca a sus mensuales: la alternancia del
     * turno no se reinicia cada mes, así que todo mes de 5 viernes invierte la
     * correspondencia ("2º viernes" coincidía con el turno B el 10-jul y ya no
     * el 14-ago). Pasando el turno del socio, las posiciones se cuentan sólo
     * sobre las semanas de ESE turno: "1ª del turno B" coincide siempre con su
     * grupo, sin retoques manuales. Sin `$cohort` el comportamiento es el de
     * siempre (posiciones sobre todas las entregas del nodo).
     *
     * En un nodo de cadencia quincenal el turno se ignora: el propio nodo ya
     * alterna, sus entregas del mes son las de su ciclo y ahí "1ª del nodo" es
     * lo que administración pide sin más anclaje.
     *
     * @param Basket $basket Ciclo semanal global.
     * @param Node $node Nodo donde se entrega.
     * @param string|null $cohort Turno A/B al que se ancla el conteo, o null
     *                            para contar todas las entregas del nodo.
     * @return int[] Posiciones que este basket sirve: 1-based desde el principio
     *               y su equivalente negativo desde el final (vacío si no entrega).
     */
    public function ordersServedBy(Basket $basket, Node $node, ?string $cohort = null): array
    {
        if ($this->nodeDeliveryDate->physicalDateFor($basket, $node) === null) {
            return [];
        }

        // Punto mensual: si reparte esta semana, es SU semana del mes y no hay
        // posiciones que contar. Ver MONTHLY_NODE_ORDERS.
        if ($node->isMonthly()) {
            return self::MONTHLY_NODE_ORDERS;
        }

        $baseline = $this->nodeDeliveryDate->baselineDateFor($basket, $node);
        if ($baseline === null) {
            return [];
        }

        $entries = $this->baselineMonthDeliveries($baseline, $node);
        $anchoredToCohort = $cohort !== null && $node->getCadence() === Node::CADENCE_WEEKLY;
        if ($anchoredToCohort) {
            $entries = $this->onlyCohort($entries, $cohort);
            // El basket puede haber quedado fuera: su semana es del otro turno,
            // así que no sirve ninguna posición de este.
            if (!$this->containsBasket($entries, $basket)) {
                return [];
            }
        }

        $orders = [];
        $lastOperativeIdx = null;
        foreach ($entries as $idx => $entry) {
            if ($entry['operative']) {
                $lastOperativeIdx = $idx;
            }
        }
        foreach ($entries as $idx => $entry) {
            if ($entry['operative']) {
                if ($entry['basketId'] === $basket->getId()) {
                    $orders[] = $idx + 1;
                }
                continue;
            }
            // Posición cancelada: ¿su fallback es este basket? Siguiente
            // operativa del mes; si no la hay, la última operativa.
            $fallbackIdx = null;
            for ($j = $idx + 1, $n = count($entries); $j < $n; $j++) {
                if ($entries[$j]['operative']) {
                    $fallbackIdx = $j;
                    break;
                }
            }
            $fallbackIdx ??= $lastOperativeIdx;
            if ($fallbackIdx !== null && $entries[$fallbackIdx]['basketId'] === $basket->getId()) {
                $orders[] = $idx + 1;
            }
        }

        // Equivalente negativo de cada posición servida, contado desde el final
        // del mes: posición p en un mes de N semanas ⇒ p - N - 1 (la última, p=N,
        // da -1). Permite emparejar mensuales que eligieron "última semana".
        $count = count($entries);
        $orders = array_merge(
            $orders,
            array_map(static fn (int $order): int => $order - $count - 1, $orders),
        );

        // ACOTACIÓN al anclar a un turno: un turno tiene 2 entregas al mes (3 en
        // los meses largos), pero un cierre global desplaza la alternancia y
        // puede dejarle UNA sola. Sin acotar, un socio anclado a la "2ª del
        // turno" desaparecería ese mes, que es el peor fallo posible aquí — lo
        // cazó L17 con el cierre del 6-ago-2027, donde el turno B se queda con
        // el 20-ago como única entrega. La última entrega del turno absorbe
        // cualquier posición que se pase de largo, igual que hace
        // {@see EggDeliveryResolver} con los huevos (min($order, $count)).
        //
        // Va DESPUÉS del mapeo negativo a propósito: una posición desbordada no
        // tiene espejo (su negativo saldría 0 o pisaría posiciones reales).
        // Sólo aplica al camino anclado: el histórico (posiciones sobre los
        // viernes del mes) se deja como estaba.
        if ($anchoredToCohort && $lastOperativeIdx !== null
            && $entries[$lastOperativeIdx]['basketId'] === $basket->getId()) {
            for ($overflow = $count + 1; $overflow <= self::MAX_ANCHORED_ORDER; $overflow++) {
                $orders[] = $overflow;
            }
        }

        sort($orders);

        return $orders;
    }

    /**
     * Filtra el calendario base del mes a las semanas del turno dado, y
     * REINDEXA: las posiciones se cuentan sobre la lista resultante, así que
     * los índices deben ser contiguos desde 0. Las semanas canceladas del turno
     * se conservan (con `operative` false) para que el emparejamiento siga
     * siendo pegajoso dentro del turno.
     *
     * @param array<int,array{basketId:int,basket:Basket,baseline:string,operative:bool}> $entries
     * @param string $cohort Turno A/B.
     * @return array<int,array{basketId:int,basket:Basket,baseline:string,operative:bool}>
     */
    private function onlyCohort(array $entries, string $cohort): array
    {
        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => $this->biweeklyCohort->cohortForBasket($entry['basket']) === $cohort,
        ));
    }

    /**
     * ¿Aparece el basket entre las entregas dadas?
     *
     * @param array<int,array{basketId:int,basket:Basket,baseline:string,operative:bool}> $entries
     * @param Basket $basket
     * @return bool
     */
    private function containsBasket(array $entries, Basket $basket): bool
    {
        foreach ($entries as $entry) {
            if ($entry['basketId'] === $basket->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calendario BASE del mes para el nodo: entregas cuyo mes de fecha base
     * coincide con el de $monthRef, ordenadas por fecha base ascendente, cada
     * una marcada como operativa o no (cancelada). Misma ventana ±8 días que
     * {@see monthDeliveries} para capturar traslados entre meses vecinos.
     *
     * @param \DateTimeImmutable $monthRef Fecha base que define el mes objetivo.
     * @param Node $node Nodo donde se entrega.
     * @return array<int,array{basketId:int,basket:Basket,baseline:string,operative:bool}>
     */
    private function baselineMonthDeliveries(\DateTimeImmutable $monthRef, Node $node): array
    {
        $year  = (int) $monthRef->format('Y');
        $month = (int) $monthRef->format('m');

        $entries = [];
        foreach ($this->monthWindowBaskets($monthRef) as $candidate) {
            $baseline = $this->nodeDeliveryDate->baselineDateFor($candidate, $node);
            if ($baseline === null) {
                continue;
            }
            if ((int) $baseline->format('Y') === $year && (int) $baseline->format('m') === $month) {
                $entries[] = [
                    'basketId'  => $candidate->getId(),
                    'basket'    => $candidate,
                    'baseline'  => $baseline->format('Y-m-d'),
                    'operative' => $this->nodeDeliveryDate->physicalDateFor($candidate, $node) !== null,
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['baseline'], $b['baseline']));

        return $entries;
    }

    /**
     * Entregas operativas del nodo cuyo mes de FECHA FÍSICA coincide con el de
     * $monthRef, ordenadas por esa fecha física ascendente. Cada entrada es
     * ['basketId' => int, 'physical' => string 'Y-m-d'].
     *
     * Se carga una ventana de ±8 días alrededor del mes para capturar Baskets
     * trasladados desde/hacia meses vecinos (los traslados de festivo van al
     * jueves anterior, así que un Basket de día 1 puede caer en el mes previo),
     * y se filtra por el mes de la fecha física real de cada uno.
     *
     * @param \DateTimeImmutable $monthRef Fecha física que define el mes objetivo.
     * @param Node $node Nodo donde se entrega.
     * @return array<int,array{basketId:int,physical:string}>
     */
    private function monthDeliveries(\DateTimeImmutable $monthRef, Node $node): array
    {
        $year  = (int) $monthRef->format('Y');
        $month = (int) $monthRef->format('m');

        $entries = [];
        foreach ($this->monthWindowBaskets($monthRef) as $candidate) {
            $phys = $this->nodeDeliveryDate->physicalDateFor($candidate, $node);
            if ($phys === null) {
                continue;
            }
            if ((int) $phys->format('Y') === $year && (int) $phys->format('m') === $month) {
                $entries[] = ['basketId' => $candidate->getId(), 'physical' => $phys->format('Y-m-d')];
            }
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['physical'], $b['physical']));

        return $entries;
    }

    /**
     * Baskets de la ventana ±8 días alrededor del mes de $monthRef, en orden de
     * fecha cruda. La ventana captura Baskets trasladados desde/hacia meses
     * vecinos (los traslados de festivo van al jueves anterior, así que un
     * Basket de día 1 puede caer en el mes previo).
     *
     * @param \DateTimeImmutable $monthRef Fecha que define el mes objetivo.
     * @return Basket[]
     */
    private function monthWindowBaskets(\DateTimeImmutable $monthRef): array
    {
        $from = $monthRef->modify('first day of this month')->modify('-8 days');
        $to   = $monthRef->modify('last day of this month')->modify('+8 days');

        $dql = "SELECT b FROM App\\Entity\\Basket b
                WHERE b.date BETWEEN :from AND :to
                ORDER BY b.date ASC";

        return $this->em->createQuery($dql)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getResult();
    }
}
