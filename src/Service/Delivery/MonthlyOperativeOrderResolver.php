<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Determina la posición operativa (1, 2, 3, 4, …) de un Basket dentro de los
 * viernes hábiles de su mes. Sirve para emparejar mensuales con su semana
 * de reparto: un mensual con day_month_order = 4 debe recoger el 4º viernes
 * operativo del mes, independientemente del calendario.
 *
 * "Operativo" significa "hay reparto ese día". Excluye los viernes festivos
 * conocidos (1-may, 25-dic en 2026). Si en el futuro se modela una entidad
 * Holiday/DeliveryException con calendario completo, esta clase debería
 * leerla; por ahora hardcode minimal de los festivos viernes.
 *
 * Ejemplo mayo 2026: viernes calendar [1, 8, 15, 22, 29]. El 1-may es
 * festivo → operativos [8, 15, 22, 29] → posiciones 1..4. Por tanto
 * para Basket(29-may), operativeOrderInMonth devuelve 4.
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
    ) {
    }

    /**
     * @return int 1-based. 1 = primer viernes operativo del mes.
     * @throws \RuntimeException Si el basket no aparece entre los del mes (no debería pasar).
     */
    public function operativeOrderInMonth(Basket $basket): int
    {
        $date = $basket->getDate();

        $dql = "SELECT b FROM App\\Entity\\Basket b
                WHERE YEAR(b.date) = :year AND MONTH(b.date) = :month
                ORDER BY b.date ASC";
        $monthBaskets = $this->em->createQuery($dql)
            ->setParameter('year', (int) $date->format('Y'))
            ->setParameter('month', (int) $date->format('m'))
            ->getResult();

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
            $date->format('Y-m-d')
        ));
    }
}
