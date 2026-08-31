<?php

namespace App\Repository;

use App\Entity\LarOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method LarOffer|null find($id, $lockMode = null, $lockVersion = null)
 * @method LarOffer|null findOneBy(array $criteria, array $orderBy = null)
 * @method LarOffer[]    findAll()
 * @method LarOffer[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LarOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LarOffer::class);
    }
}
