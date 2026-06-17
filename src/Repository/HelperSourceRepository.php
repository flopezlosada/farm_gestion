<?php

namespace App\Repository;

use App\Entity\HelperSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method HelperSource|null find($id, $lockMode = null, $lockVersion = null)
 * @method HelperSource|null findOneBy(array $criteria, array $orderBy = null)
 * @method HelperSource[]    findAll()
 * @method HelperSource[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HelperSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HelperSource::class);
    }
}
