<?php

namespace App\Repository;

use App\Entity\Survey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Survey>
 */
class SurveyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Survey::class);
    }

    /**
     * Encuestas que admiten respuestas ahora mismo (estado abierto), de la más
     * reciente a la más antigua. Es lo que ve el socix en su panel.
     *
     * @return Survey[]
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :open')
            ->setParameter('open', Survey::STATUS_OPEN)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
