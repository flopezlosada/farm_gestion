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
 */
class MonthlyOperativeOrderResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
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
     * @param Basket $basket Ciclo semanal global.
     * @param Node $node Nodo donde se entrega.
     * @return int[] Posiciones que este basket sirve: 1-based desde el principio
     *               y su equivalente negativo desde el final (vacío si no entrega).
     */
    public function ordersServedBy(Basket $basket, Node $node): array
    {
        if ($this->nodeDeliveryDate->physicalDateFor($basket, $node) === null) {
            return [];
        }

        $baseline = $this->nodeDeliveryDate->baselineDateFor($basket, $node);
        if ($baseline === null) {
            return [];
        }

        $entries = $this->baselineMonthDeliveries($baseline, $node);

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

        sort($orders);

        return $orders;
    }

    /**
     * Calendario BASE del mes para el nodo: entregas cuyo mes de fecha base
     * coincide con el de $monthRef, ordenadas por fecha base ascendente, cada
     * una marcada como operativa o no (cancelada). Misma ventana ±8 días que
     * {@see monthDeliveries} para capturar traslados entre meses vecinos.
     *
     * @param \DateTimeImmutable $monthRef Fecha base que define el mes objetivo.
     * @param Node $node Nodo donde se entrega.
     * @return array<int,array{basketId:int,baseline:string,operative:bool}>
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
