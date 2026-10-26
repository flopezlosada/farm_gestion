<?php

namespace App\Repository;

use App\Entity\Worker;
use App\Entity\WorkerSalary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerSalary>
 */
class WorkerSalaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerSalary::class);
    }

    /**
     * Los tramos que tocan algún día del año, de todos los trabajadores, con su
     * trabajador ya cargado. Es todo lo que necesita el coste del año de una vez.
     *
     * @return list<WorkerSalary>
     */
    public function findTouchingYear(int $year): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('w')
            ->join('s.worker', 'w')
            ->andWhere('s.validFrom <= :to')
            ->andWhere('s.validTo IS NULL OR s.validTo >= :from')
            ->setParameter('from', new \DateTimeImmutable($year.'-01-01'))
            ->setParameter('to', new \DateTimeImmutable($year.'-12-31'))
            ->orderBy('w.name', 'ASC')
            ->addOrderBy('s.validFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todos los tramos de un trabajador, del más antiguo al más reciente.
     *
     * @return list<WorkerSalary>
     */
    public function findForWorker(Worker $worker): array
    {
        return $this->findBy(['worker' => $worker], ['validFrom' => 'ASC']);
    }

    /**
     * Todos los tramos, agrupados por trabajador: `[workerId => list<WorkerSalary>]`.
     *
     * @return array<int, list<WorkerSalary>>
     */
    public function findAllByWorker(): array
    {
        $out = [];
        foreach ($this->findBy([], ['validFrom' => 'ASC']) as $salary) {
            $out[(int) $salary->getWorker()?->getId()][] = $salary;
        }

        return $out;
    }
}
