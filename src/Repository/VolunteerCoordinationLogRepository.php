<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerCoordinationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Los partes de horas de coordinación.
 *
 * @extends ServiceEntityRepository<VolunteerCoordinationLog>
 */
class VolunteerCoordinationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerCoordinationLog::class);
    }

    /**
     * Los minutos que este socix ha apuntado por coordinar, en el periodo.
     *
     * Se suman al contador junto con los de las tareas: para quien mira sus
     * horas es todo lo mismo —tiempo que le ha dedicado a la asociación— y
     * separarlo en dos cifras obligaría a sumarlas de cabeza.
     *
     * @param Partner            $partner el socix
     * @param \DateTimeInterface $from    inicio del periodo, inclusive
     * @param \DateTimeInterface $to      fin del periodo, inclusive
     *
     * @return int minutos apuntados por coordinación
     */
    public function sumMinutes(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.minutes), 0)')
            ->where('l.partner = :partner')
            ->andWhere('l.happenedOn BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Lo que este socix lleva apuntado en el periodo, de lo más reciente a lo
     * más antiguo. Es lo que ve en su panel para no apuntar dos veces lo mismo.
     *
     * @param Partner            $partner el socix
     * @param \DateTimeInterface $from    inicio del periodo, inclusive
     * @param \DateTimeInterface $to      fin del periodo, inclusive
     *
     * @return list<VolunteerCoordinationLog> sus partes
     */
    public function findFor(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.partner = :partner')
            ->andWhere('l.happenedOn BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('l.happenedOn', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
