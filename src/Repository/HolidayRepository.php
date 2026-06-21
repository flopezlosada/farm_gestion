<?php

namespace App\Repository;

use App\Entity\Holiday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Holiday|null find($id, $lockMode = null, $lockVersion = null)
 * @method Holiday|null findOneBy(array $criteria, array $orderBy = null)
 * @method Holiday[]    findAll()
 * @method Holiday[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HolidayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Holiday::class);
    }

    /**
     * Festivos que caen en el rango [from, to], indexados por fecha 'Y-m-d' para
     * un lookup O(1) al construir huecos y calendarios. El valor es el nombre del
     * festivo (útil para etiquetar la celda del calendario).
     *
     * @param \DateTimeImmutable $from Inicio del rango (incluido).
     * @param \DateTimeImmutable $to   Fin del rango (incluido).
     * @return array<string, string> Nombre del festivo por fecha 'Y-m-d'.
     */
    public function findNamesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('h')
            ->andWhere('h.date >= :from')
            ->andWhere('h.date <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $byDate = [];
        foreach ($rows as $holiday) {
            $byDate[$holiday->getDate()->format('Y-m-d')] = $holiday->getName();
        }

        return $byDate;
    }

    /**
     * Festivos ordenados por fecha ascendente (de enero a diciembre). Es la lista
     * del CRUD de festivos.
     *
     * @return Holiday[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('h')
            ->orderBy('h.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
