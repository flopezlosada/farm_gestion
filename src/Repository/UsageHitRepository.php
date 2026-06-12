<?php

namespace App\Repository;

use App\Entity\UsageHit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UsageHit>
 */
class UsageHitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageHit::class);
    }

    /**
     * Rutas más visitadas de un área desde una fecha, de más a menos. Base de
     * la pantalla de estadísticas: "qué pantallas usan los socixs".
     *
     * @return array<int, array{routeName: ?string, hits: int}>
     */
    public function topRoutes(string $area, \DateTimeInterface $since, int $limit = 30): array
    {
        return $this->createQueryBuilder('h')
            ->select('h.routeName AS routeName', 'COUNT(h.id) AS hits')
            ->where('h.area = :area')
            ->andWhere('h.occurredAt >= :since')
            ->setParameter('area', $area)
            ->setParameter('since', $since)
            ->groupBy('h.routeName')
            ->orderBy('hits', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rutas que han devuelto errores (status >= 400) desde una fecha, con su
     * conteo, de más a menos. Base de "qué les falla".
     *
     * @return array<int, array{routeName: ?string, statusCode: int, hits: int}>
     */
    public function errorsByRoute(string $area, \DateTimeInterface $since, int $limit = 30): array
    {
        return $this->createQueryBuilder('h')
            ->select('h.routeName AS routeName', 'h.statusCode AS statusCode', 'COUNT(h.id) AS hits')
            ->where('h.area = :area')
            ->andWhere('h.occurredAt >= :since')
            ->andWhere('h.statusCode >= 400')
            ->setParameter('area', $area)
            ->setParameter('since', $since)
            ->groupBy('h.routeName', 'h.statusCode')
            ->orderBy('hits', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Borra el rastro anterior a una fecha. Lo usa app:purge-usage-hits para
     * la retención (minimización LOPD: no guardar para siempre).
     *
     * @return int filas borradas
     */
    public function deleteOlderThan(\DateTimeInterface $before): int
    {
        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->where('h.occurredAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
