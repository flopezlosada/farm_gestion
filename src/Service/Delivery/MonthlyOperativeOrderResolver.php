<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Determina la posición operativa (1, 2, 3, 4, …) de un Basket dentro de los
 * viernes hábiles de su mes. Sirve para emparejar mensuales con su semana
 * de reparto: un mensual con day_month_order = 4 debe recoger el 4º viernes
 * operativo del mes, independientemente del calendario.
 *
 * "Operativo" significa "hay reparto ese día". Para nodos weekly de viernes
 * (Torremocha), el método legacy {@see operativeOrderInMonth} aplica el
 * hardcode de festivos {@see NON_OPERATIVE_FRIDAYS} (1-may, 25-dic). Para
 * nodos con día y cadencia distintos (Cascorro/Midori miércoles biweekly),
 * usar {@see operativeOrderForNode}: cuenta entregas reales del nodo en el
 * mes apoyándose en {@see NodeDeliveryDate} (que respeta cadencia y
 * excepciones de calendario en BBDD).
 *
 * Sub-fase 8.8b3 (2026-05-28): añadido operativeOrderForNode. El hardcode
 * NON_OPERATIVE_FRIDAYS sigue mientras no se migren los festivos a
 * DeliveryException (paso "festivos" del roadmap).
 *
 * Ejemplo mayo 2026 weekly (Torremocha): viernes [1, 8, 15, 22, 29]. El
 * 1-may es festivo → operativos [8, 15, 22, 29] → posiciones 1..4. Para
 * Basket(29-may), operativeOrderInMonth devuelve 4.
 *
 * Ejemplo mayo 2026 biweekly (Cascorro, anchor 6-may): entrega 6-may y
 * 20-may → posiciones 1 y 2. Para Basket(22-may), operativeOrderForNode
 * con Cascorro devuelve 2.
 */
class MonthlyOperativeOrderResolver
{
    /**
     * Viernes que NO son operativos por festivo. Mantener al día por año.
     * Si pasamos de 2026 sin actualizar, los repartos se desplazarán uno
     * cada mes con festivo en viernes.
     */
    private const NON_OPERATIVE_FRIDAYS = [
        '2026-05-01',
        '2026-12-25',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * ¿Hubo reparto ese día? Falso para los viernes festivos conocidos
     * (1-may, 25-dic en 2026): esos días no se reparte —se adelanta al
     * viernes hábil anterior—, así que su Basket no es operativo.
     */
    public function isOperative(Basket $basket): bool
    {
        $date = $basket->getDate();

        return $date !== null
            && !in_array($date->format('Y-m-d'), self::NON_OPERATIVE_FRIDAYS, true);
    }

    /**
     * Posición del Basket entre los viernes operativos del mes. Aplica el
     * hardcode de festivos viernes y NO consulta el nodo: usar sólo para
     * nodos weekly de viernes (Torremocha). Para Cascorro/Midori u otros
     * nodos no-viernes, usar {@see operativeOrderForNode}.
     *
     * @return int 1-based. 1 = primer viernes operativo del mes.
     * @throws \RuntimeException Si el basket no aparece entre los del mes (no debería pasar).
     */
    public function operativeOrderInMonth(Basket $basket): int
    {
        $monthBaskets = $this->loadMonthBaskets($basket->getDate());

        $position = 0;
        foreach ($monthBaskets as $candidate) {
            if (in_array($candidate->getDate()->format('Y-m-d'), self::NON_OPERATIVE_FRIDAYS, true)) {
                continue;
            }
            $position++;
            if ($candidate->getId() === $basket->getId()) {
                return $position;
            }
        }

        throw new \RuntimeException(sprintf(
            'Basket id=%d (%s) no encontrado entre los viernes operativos del mes.',
            $basket->getId(),
            $basket->getDate()->format('Y-m-d')
        ));
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
     * Para Torremocha weekly (viernes), cuenta todos los viernes del mes
     * salvo los que tengan DeliveryException cancelando. Mientras los
     * festivos sigan en hardcode (no en BBDD), Torremocha debe seguir
     * usando {@see operativeOrderInMonth}.
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
