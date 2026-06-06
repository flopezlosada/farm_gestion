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

        $firstOfMonth = $monthRef->modify('first day of this month');
        $from = $firstOfMonth->modify('-8 days');
        $to   = $monthRef->modify('last day of this month')->modify('+8 days');

        $dql = "SELECT b FROM App\\Entity\\Basket b
                WHERE b.date BETWEEN :from AND :to
                ORDER BY b.date ASC";
        $baskets = $this->em->createQuery($dql)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getResult();

        $entries = [];
        foreach ($baskets as $candidate) {
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
}
