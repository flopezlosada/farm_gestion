<?php

namespace App\Repository;

use App\Entity\DeliveryException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryException>
 */
class DeliveryExceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryException::class);
    }

    /**
     * Localiza la excepción registrada para un viernes concreto, si la hay.
     *
     * @param \DateTimeInterface $friday Fecha del viernes a comprobar.
     * @return DeliveryException|null Excepción registrada, o null si el viernes opera normal.
     */
    public function findByFriday(\DateTimeInterface $friday): ?DeliveryException
    {
        return $this->createQueryBuilder('e')
            ->where('e.fridayDate = :friday')
            ->setParameter('friday', $friday->format('Y-m-d'))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Excepciones cuya fecha original o desplazada cae dentro del rango dado.
     * Útil para pintar el calendario.
     *
     * @return DeliveryException[]
     */
    public function findInRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.fridayDate BETWEEN :from AND :to')
            ->orWhere('e.shiftedDate BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('e.fridayDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
