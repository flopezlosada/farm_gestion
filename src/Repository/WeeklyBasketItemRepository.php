<?php

namespace App\Repository;

use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method WeeklyBasketItem|null find($id, $lockMode = null, $lockVersion = null)
 * @method WeeklyBasketItem|null findOneBy(array $criteria, array $orderBy = null)
 * @method WeeklyBasketItem[]    findAll()
 * @method WeeklyBasketItem[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WeeklyBasketItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyBasketItem::class);
    }

    /**
     * Devuelve los ítems materializados de una entrega concreta.
     *
     * @param WeeklyBasket $weeklyBasket
     * @return WeeklyBasketItem[]
     */
    public function findForWeeklyBasket(WeeklyBasket $weeklyBasket): array
    {
        return $this->findBy(['weeklyBasket' => $weeklyBasket]);
    }

    /**
     * Mapa de cantidades por componente para un conjunto de entregas, en UNA
     * sola query (evita el N+1 de pedir los ítems entrega a entrega). Pensado
     * para el listado de reparto, que pinta decenas de filas de golpe.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return array<int, array<int, float>> [weeklyBasketId => [basketComponentId => amount]]
     */
    public function componentAmountsFor(array $weeklyBaskets): array
    {
        if ($weeklyBaskets === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.weeklyBasket) AS wbId', 'IDENTITY(i.basketComponent) AS compId', 'i.amount AS amount')
            ->where('i.weeklyBasket IN (:wbs)')
            ->setParameter('wbs', $weeklyBaskets)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['wbId']][(int) $row['compId']] = (float) $row['amount'];
        }

        return $map;
    }
}
