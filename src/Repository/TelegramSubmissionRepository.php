<?php

namespace App\Repository;

use App\Entity\TelegramSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramSubmission>
 */
class TelegramSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramSubmission::class);
    }

    /**
     * Los que esperan a que alguien diga qué son, del más antiguo al más nuevo.
     *
     * @return list<TelegramSubmission>
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => TelegramSubmission::STATUS_PENDING], ['createdAt' => 'ASC']);
    }

    /**
     * Los últimos ya resueltos, para poder corregir lo que el bot hizo solo.
     *
     * @param int $limit Cuántos.
     *
     * @return list<TelegramSubmission>
     */
    public function findRecentResolved(int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status != :pending')
            ->setParameter('pending', TelegramSubmission::STATUS_PENDING)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
