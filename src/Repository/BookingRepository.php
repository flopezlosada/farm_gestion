<?php

namespace App\Repository;

use App\Entity\Booking;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Booking|null find($id, $lockMode = null, $lockVersion = null)
 * @method Booking|null findOneBy(array $criteria, array $orderBy = null)
 * @method Booking[]    findAll()
 * @method Booking[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Eventos vigentes para la agenda pública: los que aún no han terminado
     * (si no tienen fecha de fin, los que aún no han empezado), ordenados del
     * más próximo al más lejano. Se compara contra la medianoche de hoy para
     * que un evento siga listado durante todo su día.
     *
     * @param int|null $limit Máximo de eventos a devolver; null = sin límite.
     * @return Booking[]
     */
    public function findUpcoming(?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('COALESCE(b.endAt, b.beginAt) >= :today')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('b.beginAt', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }
}
