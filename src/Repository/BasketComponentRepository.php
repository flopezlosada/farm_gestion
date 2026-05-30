<?php

namespace App\Repository;

use App\Entity\BasketComponent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BasketComponent|null find($id, $lockMode = null, $lockVersion = null)
 * @method BasketComponent|null findOneBy(array $criteria, array $orderBy = null)
 * @method BasketComponent[]    findAll()
 * @method BasketComponent[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BasketComponentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BasketComponent::class);
    }
}
