<?php

namespace App\Repository;

use App\Entity\TimeEntry;
use App\Entity\Worker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method TimeEntry|null find($id, $lockMode = null, $lockVersion = null)
 * @method TimeEntry|null findOneBy(array $criteria, array $orderBy = null)
 * @method TimeEntry[]    findAll()
 * @method TimeEntry[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TimeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeEntry::class);
    }

    /**
     * Fichajes VIGENTES (no anulados) de un trabajador dentro de un rango de
     * instantes [desde, hasta), ordenados cronológicamente. Es la base para
     * emparejar entradas/salidas y calcular las horas al vuelo, y para pintar el
     * registro y el justificante. Filtra por `occurredAt` (la hora que cuenta),
     * no por la de inserción.
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeImmutable $from   Inicio del rango, incluido (UTC).
     * @param \DateTimeImmutable $to     Fin del rango, excluido (UTC).
     * @return TimeEntry[]
     */
    public function findEffectiveForWorkerBetween(Worker $worker, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.worker = :worker')
            ->andWhere('t.voidedAt IS NULL')
            ->andWhere('t.occurredAt >= :from')
            ->andWhere('t.occurredAt < :to')
            ->setParameter('worker', $worker)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('t.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Último fichaje vigente de un trabajador (por hora de ocurrencia). Sirve
     * para saber si tiene un tramo abierto (último = entrada) y pintar el botón
     * correcto (fichar entrada vs. salida).
     *
     * @param Worker $worker Trabajador.
     * @return TimeEntry|null
     */
    public function findLastEffectiveForWorker(Worker $worker): ?TimeEntry
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.worker = :worker')
            ->andWhere('t.voidedAt IS NULL')
            ->setParameter('worker', $worker)
            ->orderBy('t.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Último fichaje vigente de un trabajador DENTRO de un rango [from, to). A
     * diferencia de {@see self::findLastEffectiveForWorker()} (último global),
     * sirve para decidir el estado del día sin arrastrar tramos abiertos de días
     * anteriores: una entrada sin cerrar de ayer no debe contar como "trabajando
     * hoy". El olvido de salida queda como anomalía que detecta el panel de
     * huecos / el aviso de salida abierta.
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeImmutable $from   Inicio del rango, incluido.
     * @param \DateTimeImmutable $to     Fin del rango, excluido.
     * @return TimeEntry|null
     */
    public function findLastEffectiveForWorkerBetween(Worker $worker, \DateTimeImmutable $from, \DateTimeImmutable $to): ?TimeEntry
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.worker = :worker')
            ->andWhere('t.voidedAt IS NULL')
            ->andWhere('t.occurredAt >= :from')
            ->andWhere('t.occurredAt < :to')
            ->setParameter('worker', $worker)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('t.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
