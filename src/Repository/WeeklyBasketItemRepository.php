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
}
