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
 * Ejemplo mayo 2026 weekly (Torremocha, traslado 1-may→30-abr y 15-may→14-may
 * registrados en delivery_exception): los Basket viernes son [1, 8, 15, 22,
 * 29]. Para 1-may y 15-may, deliversInBasket sigue devolviendo true (la
 * excepción traslada, no cancela) y operativeOrderForNode los cuenta. Para
 * Basket(29-may) → 5.
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
        if (!$this->nodeDeliveryDate->deliversInBasket($basket, $node)) {
            return null;
        }

        $monthBaskets = $this->loadMonthBaskets($basket->getDate());

        $position = 0;
        foreach ($monthBaskets as $candidate) {
            if (!$this->nodeDeliveryDate->deliversInBasket($candidate, $node)) {
                continue;
            }
            $position++;
            if ($candidate->getId() === $basket->getId()) {
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
     * Carga los Basket del mes del date dado, ordenados ascendentes.
     *
     * @param \DateTimeInterface $date
     * @return Basket[]
     */
    private function loadMonthBaskets(\DateTimeInterface $date): array
    {
        $dql = "SELECT b FROM App\\Entity\\Basket b
                WHERE YEAR(b.date) = :year AND MONTH(b.date) = :month
                ORDER BY b.date ASC";

        return $this->em->createQuery($dql)
            ->setParameter('year', (int) $date->format('Y'))
            ->setParameter('month', (int) $date->format('m'))
            ->getResult();
    }
}
