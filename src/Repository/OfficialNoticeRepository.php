<?php

namespace App\Repository;

use App\Entity\OfficialNotice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OfficialNotice>
 */
class OfficialNoticeRepository extends ServiceEntityRepository
{
    /** Revisadas que enseña la pantalla, las más recientes. */
    public const REVIEWED_SHOWN = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OfficialNotice::class);
    }

    /**
     * Si ya se avisó de ese correo.
     *
     * @param string $messageKey Huella del correo, {@see OfficialNotice::keyFor()}.
     *
     * @return bool true si ya hay un aviso con esa huella.
     */
    public function hasKey(string $messageKey): bool
    {
        return $this->count(['messageKey' => $messageKey]) > 0;
    }

    /**
     * Las que nadie ha marcado como revisadas, las más antiguas primero: son las que
     * antes cumplen los 10 días.
     *
     * @return list<OfficialNotice>
     */
    public function findUnreviewed(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.reviewedAt IS NULL')
            ->orderBy('n.receivedAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las últimas revisadas, con quién lo hizo (en la misma consulta).
     *
     * @return list<OfficialNotice>
     */
    public function findRecentReviewed(): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('u')
            ->leftJoin('n.reviewedBy', 'u')
            ->where('n.reviewedAt IS NOT NULL')
            ->orderBy('n.reviewedAt', 'DESC')
            ->setMaxResults(self::REVIEWED_SHOWN)
            ->getQuery()
            ->getResult();
    }
}
