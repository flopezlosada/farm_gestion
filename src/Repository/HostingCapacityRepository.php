<?php

namespace App\Repository;

use App\Entity\HostingCapacity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method HostingCapacity|null find($id, $lockMode = null, $lockVersion = null)
 * @method HostingCapacity|null findOneBy(array $criteria, array $orderBy = null)
 * @method HostingCapacity[]    findAll()
 * @method HostingCapacity[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HostingCapacityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostingCapacity::class);
    }

    /**
     * Aforo configurado para un mes concreto, o null si ese mes no tiene fila
     * (el caller decide el default cuando no hay configuración).
     *
     * @param int $year
     * @param int $month 1-12
     * @return HostingCapacity|null
     */
    public function findForYearMonth(int $year, int $month): ?HostingCapacity
    {
        return $this->findOneBy(['year' => $year, 'month' => $month]);
    }

    /**
     * Filas de aforo de un rango de meses, indexadas por la clave "YYYY-MM"
     * para resolver el aforo día a día sin una query por día.
     *
     * @param int $fromYear
     * @param int $fromMonth 1-12
     * @param int $toYear
     * @param int $toMonth   1-12
     * @return array<string, HostingCapacity> Clave "YYYY-MM" (mes a dos dígitos).
     */
    public function findKeyedByMonth(int $fromYear, int $fromMonth, int $toYear, int $toMonth): array
    {
        $rows = $this->createQueryBuilder('c')
            ->andWhere('(c.year * 100 + c.month) >= :from')
            ->andWhere('(c.year * 100 + c.month) <= :to')
            ->setParameter('from', $fromYear * 100 + $fromMonth)
            ->setParameter('to', $toYear * 100 + $toMonth)
            ->getQuery()
            ->getResult();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[sprintf('%04d-%02d', $row->getYear(), $row->getMonth())] = $row;
        }

        return $keyed;
    }
}
