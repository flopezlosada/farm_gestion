<?php

namespace App\Repository;

use App\Entity\UsageHit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UsageHit>
 *
 * Las consultas de estadística acotan por área y por un rango [from, until):
 * `from` inclusive, `until` exclusivo (pásale el día siguiente al último día que
 * quieras incluir). Así el rango se compone sin solapes ni días parciales.
 */
class UsageHitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageHit::class);
    }

    /**
     * Rutas más visitadas de un área en el rango, de más a menos.
     *
     * @return array<int, array{routeName: ?string, hits: int}>
     */
    public function topRoutes(string $area, \DateTimeInterface $from, \DateTimeInterface $until, int $limit = 30): array
    {
        return $this->rangeQb($area, $from, $until)
            ->select('h.routeName AS routeName', 'COUNT(h.id) AS hits')
            ->groupBy('h.routeName')
            ->orderBy('hits', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rutas que han devuelto errores (status >= 400) en el rango, de más a menos.
     *
     * @return array<int, array{routeName: ?string, statusCode: int, hits: int}>
     */
    public function errorsByRoute(string $area, \DateTimeInterface $from, \DateTimeInterface $until, int $limit = 30): array
    {
        return $this->rangeQb($area, $from, $until)
            ->select('h.routeName AS routeName', 'h.statusCode AS statusCode', 'COUNT(h.id) AS hits')
            ->andWhere('h.statusCode >= 400')
            ->groupBy('h.routeName', 'h.statusCode')
            ->orderBy('hits', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cifras de cabecera de un área en el rango: total de visitas, sesiones
     * únicas (sessionHash distintos, anónimos), pantallas distintas visitadas y
     * visitas con error (>= 400).
     *
     * @return array{total: int, sessions: int, screens: int, errors: int}
     */
    public function summary(string $area, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $row = $this->rangeQb($area, $from, $until)
            ->select(
                'COUNT(h.id) AS total',
                'COUNT(DISTINCT h.sessionHash) AS sessions',
                'COUNT(DISTINCT h.routeName) AS screens',
                'SUM(CASE WHEN h.statusCode >= 400 THEN 1 ELSE 0 END) AS errors',
            )
            ->getQuery()
            ->getSingleResult();

        return [
            'total' => (int) $row['total'],
            'sessions' => (int) $row['sessions'],
            'screens' => (int) $row['screens'],
            'errors' => (int) $row['errors'],
        ];
    }

    /**
     * Visitas, sesiones únicas y errores agrupados por día (fecha natural) en el
     * rango, en orden cronológico. Base del gráfico temporal. SQL nativo: DATE()
     * y el agrupado por día se expresan mejor en SQL que en DQL, y la serie no
     * necesita hidratar entidades.
     *
     * Devuelve solo los días con tráfico; el relleno de los días vacíos a cero
     * lo hace quien dibuja la serie (el controller), para no asumir aquí el
     * rango exacto ni la zona horaria de presentación.
     *
     * @return array<int, array{day: string, hits: int, sessions: int, errors: int}>
     */
    public function hitsPerDay(string $area, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $sql = 'SELECT DATE(occurred_at) AS day, '
             . 'COUNT(*) AS hits, '
             . 'COUNT(DISTINCT session_hash) AS sessions, '
             . 'SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS errors '
             . 'FROM usage_hit '
             . 'WHERE area = :area AND occurred_at >= :from AND occurred_at < :until '
             . 'GROUP BY DATE(occurred_at) '
             . 'ORDER BY day';

        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'area' => $area,
            'from' => $from->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(static fn (array $r): array => [
            'day' => (string) $r['day'],
            'hits' => (int) $r['hits'],
            'sessions' => (int) $r['sessions'],
            'errors' => (int) $r['errors'],
        ], $rows);
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

    /**
     * QueryBuilder base acotado por área y rango [from, until). Centraliza el
     * filtro común de todas las consultas de estadística.
     */
    private function rangeQb(string $area, \DateTimeInterface $from, \DateTimeInterface $until): QueryBuilder
    {
        return $this->createQueryBuilder('h')
            ->where('h.area = :area')
            ->andWhere('h.occurredAt >= :from')
            ->andWhere('h.occurredAt < :until')
            ->setParameter('area', $area)
            ->setParameter('from', $from)
            ->setParameter('until', $until);
    }
}
