<?php

namespace App\Repository;

use App\Entity\InternshipDetail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method InternshipDetail|null find($id, $lockMode = null, $lockVersion = null)
 * @method InternshipDetail|null findOneBy(array $criteria, array $orderBy = null)
 * @method InternshipDetail[]    findAll()
 * @method InternshipDetail[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InternshipDetailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipDetail::class);
    }
}
