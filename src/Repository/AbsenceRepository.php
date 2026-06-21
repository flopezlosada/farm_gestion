<?php

namespace App\Repository;

use App\Entity\Absence;
use App\Entity\Worker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Absence|null find($id, $lockMode = null, $lockVersion = null)
 * @method Absence|null findOneBy(array $criteria, array $orderBy = null)
 * @method Absence[]    findAll()
 * @method Absence[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /**
     * Vacaciones APROBADAS de un trabajador que solapan un año natural. Es la
     * base para calcular el saldo de vacaciones del año (días/año − días
     * aprobados). Trae las que empiezan dentro del año o lo cruzan, para que el
     * servicio recorte al tramo del año al contar días laborables.
     *
     * @param Worker $worker Trabajador.
     * @param int    $year   Año natural.
     * @return Absence[]
     */
    public function findApprovedVacationsForWorkerInYear(Worker $worker, int $year): array
    {
        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year));
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year));

        return $this->createQueryBuilder('a')
            ->andWhere('a.worker = :worker')
            ->andWhere('a.type = :type')
            ->andWhere('a.status = :status')
            ->andWhere('a.startDate <= :yearEnd')
            ->andWhere('a.endDate >= :yearStart')
            ->setParameter('worker', $worker)
            ->setParameter('type', Absence::TYPE_VACATION)
            ->setParameter('status', Absence::STATUS_APPROVED)
            ->setParameter('yearStart', $yearStart)
            ->setParameter('yearEnd', $yearEnd)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ausencias APROBADAS de un trabajador (de cualquier tipo: vacaciones, baja,
     * permiso…) que solapan el rango [from, to]. Sirve para excluir del panel de
     * huecos los días cubiertos por una ausencia justificada (un día de baja no
     * es un fichaje olvidado).
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeImmutable $from   Inicio del rango (incluido).
     * @param \DateTimeImmutable $to     Fin del rango (incluido).
     * @return Absence[]
     */
    public function findApprovedForWorkerBetween(Worker $worker, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.worker = :worker')
            ->andWhere('a.status = :status')
            ->andWhere('a.startDate <= :to')
            ->andWhere('a.endDate >= :from')
            ->setParameter('worker', $worker)
            ->setParameter('status', Absence::STATUS_APPROVED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ausencias pendientes de decisión (solicitadas), ordenadas por fecha de
     * inicio. Es la bandeja de aprobación del supervisor.
     *
     * @return Absence[]
     */
    public function findPendingRequests(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :status')
            ->setParameter('status', Absence::STATUS_REQUESTED)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
