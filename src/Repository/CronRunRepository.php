<?php

namespace App\Repository;

use App\Entity\CronRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronRun>
 */
class CronRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronRun::class);
    }

    /**
     * Última ejecución de CADA tarea, en una sola query (la pantalla de
     * configuración pinta las siete: un findOneBy por tarea sería un N+1).
     *
     * La subconsulta agrupa por tarea y se queda con el id más alto, que es
     * también el más reciente por ser autoincremental; así se evita el
     * `MAX(started_at)` con empate al segundo, y el GROUP BY sólo lleva la
     * columna agrupada (compatible con ONLY_FULL_GROUP_BY, activo en este
     * proyecto).
     *
     * @return array<string, CronRun> Clave de tarea => su última ejecución.
     */
    public function findLastRunPerTask(): array
    {
        $runs = $this->getEntityManager()
            ->createQuery(
                'SELECT r FROM ' . CronRun::class . ' r
                 WHERE r.id IN (
                     SELECT MAX(r2.id) FROM ' . CronRun::class . ' r2 GROUP BY r2.taskKey
                 )'
            )
            ->getResult();

        $byTask = [];
        foreach ($runs as $run) {
            $byTask[$run->getTaskKey()] = $run;
        }

        return $byTask;
    }

    /**
     * Consulta del histórico completo de ejecuciones, con los filtros de la
     * pantalla puestos. Sin ejecutar, para que la pagine el paginador.
     *
     * Comparte forma de filtros con la bitácora de avisos —mismo rango, mismos
     * nombres— porque las dos pestañas se miran seguidas y cambiar de una a
     * otra tiene que conservar el periodo que estabas consultando.
     *
     * @param array{from?: ?\DateTimeImmutable, until?: ?\DateTimeImmutable, task?: ?string, status?: ?string} $filters
     */
    public function findFilteredQb(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        $this->applyFilters($qb, $filters);

        return $qb;
    }

    /**
     * Cuántas ejecuciones hubo de cada resultado en el rango, en una consulta.
     *
     * Contesta de un vistazo la pregunta con la que se abre esta pestaña: ¿ha
     * fallado algo? Sin esto hay que recorrer el listado a ojo, y las que fallan
     * son justo las raras: una fila roja entre cincuenta verdes se pasa por alto.
     *
     * @param array<string, mixed> $filters Los mismos que {@see findFilteredQb()}.
     * @return array{total: int, done: int, nothing_to_do: int, disabled: int, failed: int}
     */
    public function summary(array $filters): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.status AS status, COUNT(r.id) AS n')
            ->groupBy('r.status');

        // Sin el filtro de estado, por lo mismo que en la bitácora de avisos: la
        // fila de tarjetas es el mando para saltar de un resultado a otro, y
        // heredando el filtro dejaría a cero todas menos la elegida.
        $sinEstado = $filters;
        $sinEstado['status'] = null;
        $this->applyFilters($qb, $sinEstado);

        $counts = ['total' => 0, 'done' => 0, 'nothing_to_do' => 0, 'disabled' => 0, 'failed' => 0];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $n = (int) $row['n'];
            $counts['total'] += $n;
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = $n;
            }
        }

        return $counts;
    }

    /**
     * Pone los filtros de la pantalla sobre una consulta. Lo comparten el
     * listado y el resumen para que no puedan contar cosas distintas.
     *
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['from'])) {
            $qb->andWhere('r.startedAt >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['until'])) {
            $qb->andWhere('r.startedAt < :until')->setParameter('until', $filters['until']);
        }
        if (!empty($filters['task'])) {
            $qb->andWhere('r.taskKey = :task')->setParameter('task', $filters['task']);
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('r.status = :status')->setParameter('status', $filters['status']);
        }
    }

    /**
     * Borra las ejecuciones anteriores a una fecha y devuelve cuántas.
     *
     * La bitácora de avisos enlaza con estas filas; la clave ajena deja el
     * enlace en blanco al borrarlas, así que un aviso viejo no desaparece, sólo
     * deja de saber de qué pasada salió. Por eso las dos tablas se purgan con
     * el mismo plazo.
     */
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.startedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * Historial reciente de una tarea, de más nueva a más vieja.
     *
     * @param string $taskKey Clave de la tarea en el manifiesto.
     * @param int    $limit   Cuántas ejecuciones devolver.
     * @return CronRun[]
     */
    public function findRecentForTask(string $taskKey, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.taskKey = :task')
            ->setParameter('task', $taskKey)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
