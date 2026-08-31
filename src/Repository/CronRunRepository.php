<?php

namespace App\Repository;

use App\Entity\CronRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
