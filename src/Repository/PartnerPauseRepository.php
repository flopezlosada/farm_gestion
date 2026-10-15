<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerPause;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerPause>
 */
class PartnerPauseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerPause::class);
    }

    /**
     * Las pausas de un socix, de la más antigua a la más reciente.
     *
     * @return list<PartnerPause>
     */
    public function findForPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.partner = :partner')->setParameter('partner', $partner)
            ->orderBy('p.startsOn', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Las pausas que siguen contando a partir de un día: la que esté en curso y las
     * programadas. Las ya terminadas quedan fuera.
     *
     * @param \DateTimeInterface $day Día de referencia (normalmente hoy).
     * @return list<PartnerPause>
     */
    public function findNotEndedBefore(Partner $partner, \DateTimeInterface $day): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.partner = :partner')->setParameter('partner', $partner)
            ->andWhere('p.resumesOn IS NULL OR p.resumesOn > :day')->setParameter('day', PartnerPause::day($day))
            ->orderBy('p.startsOn', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Todas las pausas que tocan el periodo [desde, hasta], de todxs lxs socixs, con
     * su socix ya cargado. Es la carga en lote de quien pregunta por muchas semanas
     * o muchxs socixs a la vez (las cuotas de un mes).
     *
     * @return list<PartnerPause>
     */
    public function findTouching(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('pt')->join('p.partner', 'pt')
            ->andWhere('p.startsOn <= :until')->setParameter('until', PartnerPause::day($until))
            ->andWhere('p.resumesOn IS NULL OR p.resumesOn > :from')->setParameter('from', PartnerPause::day($from))
            ->getQuery()->getResult();
    }

    /**
     * Ids de lxs socixs con una pausa que cubre ese día.
     *
     * @return list<int>
     */
    public function findPausedPartnerIdsOn(\DateTimeInterface $day): array
    {
        $day = PartnerPause::day($day);

        return array_map('intval', $this->createQueryBuilder('p')
            ->select('DISTINCT IDENTITY(p.partner)')
            ->andWhere('p.startsOn <= :day')
            ->andWhere('p.resumesOn IS NULL OR p.resumesOn > :day')
            ->setParameter('day', $day)
            ->getQuery()->getSingleColumnResult());
    }
}
