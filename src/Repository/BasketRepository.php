<?php

namespace App\Repository;

use App\Entity\Basket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Basket>
 */
class BasketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Basket::class);
    }

    public function findBasketByWeekYear($date)
    {
        $em = $this->getEntityManager();
        $dql = "select s from App\\Entity\\Basket s where s.week=:week and  YEAR(s.date)=:year ";
        $query = $em->createQuery($dql);
        $query->setParameter("week", date('W', strtotime($date)));
        $query->setParameter("year", date('Y', strtotime($date)));


        if ($query->getResult()) {

           return $query->getSingleResult();

        }

        return false;
    }

    /**
     * Basket inmediatamente posterior a uno dado por fecha (el viernes
     * siguiente del calendario CSA). Devuelve null si no hay Basket
     * futuro registrado.
     */
    public function findNextAfter(\App\Entity\Basket $basket): ?\App\Entity\Basket
    {
        return $this->createQueryBuilder('b')
            ->where('b.date > :date')
            ->setParameter('date', $basket->getDate())
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Basket inmediatamente anterior a uno dado por fecha. Útil para
     * calcular el equilibrio de cargas entre viernes consecutivos.
     */
    public function findPreviousBefore(\App\Entity\Basket $basket): ?\App\Entity\Basket
    {
        return $this->createQueryBuilder('b')
            ->where('b.date < :date')
            ->setParameter('date', $basket->getDate())
            ->orderBy('b.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Otros viernes del mismo mes/año que `$basket` cuyo destino aún tiene
     * sentido: posteriores al `$basket` dado. No se devuelven viernes
     * anteriores ni el propio `$basket`, porque cambiar la cesta a un
     * viernes que ya pasó (físicamente recogido o no) no es operativo.
     *
     * Usado por la regla Window de mensuales: el destino válido es
     * cualquier viernes del mismo mes posterior al `$basket`.
     *
     * @return \App\Entity\Basket[]
     */
    public function findOtherBasketsInSameMonth(\App\Entity\Basket $basket): array
    {
        return $this->createQueryBuilder('b')
            ->where('YEAR(b.date) = :y')
            ->andWhere('MONTH(b.date) = :m')
            ->andWhere('b.date > :from_date')
            ->setParameter('y', $basket->getDate()->format('Y'))
            ->setParameter('m', $basket->getDate()->format('m'))
            ->setParameter('from_date', $basket->getDate()->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Basket cuya fecha cae entre dos fechas (inclusive ambos extremos),
     * ordenados por fecha ascendente. Útil para iterar viernes
     * consecutivos en una ventana.
     *
     * @return \App\Entity\Basket[]
     */
    public function findBetweenDates(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.date BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
