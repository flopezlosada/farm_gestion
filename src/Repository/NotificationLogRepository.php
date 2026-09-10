<?php

namespace App\Repository;

use App\Entity\NotificationLog;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consultas sobre la bitácora de avisos ({@see NotificationLog}).
 *
 * @extends ServiceEntityRepository<NotificationLog>
 */
class NotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationLog::class);
    }

    /**
     * Consulta del listado, con todos los filtros de la pantalla aplicados.
     * Devuelve el QueryBuilder sin ejecutar para que el paginador cuente y
     * trocee él (traerlo entero y paginar en PHP se cae el día que haya
     * cincuenta mil filas).
     *
     * @param array{
     *     from?: ?\DateTimeImmutable, until?: ?\DateTimeImmutable, channel?: ?string,
     *     kind?: ?string, status?: ?string, q?: ?string, run?: ?int
     * } $filters `until` es límite superior EXCLUSIVO (día siguiente a medianoche).
     */
    public function findFilteredQb(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.partner', 'p')->addSelect('p')
            ->leftJoin('l.cronRun', 'r')->addSelect('r')
            ->orderBy('l.sentAt', 'DESC')
            ->addOrderBy('l.id', 'DESC');

        $this->applyFilters($qb, $filters);

        return $qb;
    }

    /**
     * Cuántos avisos hay de cada estado con esos filtros puestos. Alimenta los
     * indicadores de cabecera, y se resuelve en UNA consulta agrupada: pedir
     * cuatro conteos sería cuatro escaneos del mismo rango.
     *
     * @param array<string, mixed> $filters Los mismos que {@see findFilteredQb()}.
     * @return array{total: int, sent: int, failed: int, discarded: int}
     */
    public function summary(array $filters): array
    {
        // El mismo join que el listado aunque aquí no se seleccione nada de la
        // persona: los filtros son compartidos y uno de ellos busca por su
        // nombre. Si las dos consultas no tienen los mismos alias disponibles,
        // la cabecera y la tabla dejan de contar lo mismo.
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.partner', 'p')
            ->select('l.status AS status, COUNT(l.id) AS n')
            ->groupBy('l.status');

        $this->applyFilters($qb, $filters);

        $counts = ['total' => 0, 'sent' => 0, 'failed' => 0, 'discarded' => 0];
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
     * Tipos de aviso presentes en la bitácora, para poblar el desplegable del
     * filtro. Se leen de los datos y no de una lista fija a propósito: así un
     * aviso nuevo aparece solo, sin que nadie tenga que acordarse de darlo de
     * alta en la pantalla.
     *
     * @return string[]
     */
    public function distinctKinds(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.kind AS kind')
            ->orderBy('l.kind', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'kind');
    }

    /**
     * Los avisos de una persona, de más nuevo a más viejo.
     *
     * Cruza por DOS caminos y no por uno: el push conoce a la persona y deja
     * `partner_id`, pero el correo solo conoce la dirección a la que escribió.
     * Buscar únicamente por `partner_id` dejaría fuera todos los correos; buscar
     * sólo por dirección, todos los avisos al móvil.
     *
     * @param Partner $partner De quién.
     * @param int     $limit   Cuántos como mucho.
     * @return NotificationLog[]
     */
    public function findForPartner(Partner $partner, int $limit = 15): array
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.sentAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit);

        $email = $partner->getEmail();
        if ($email) {
            $qb->where('l.partner = :partner OR l.target = :email')
                ->setParameter('partner', $partner)
                ->setParameter('email', $email);
        } else {
            $qb->where('l.partner = :partner')->setParameter('partner', $partner);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Cuántos avisos salieron de cada ejecución, para la columna del histórico
     * de tareas. En una sola consulta por la misma razón que arriba: la pantalla
     * pinta veinticinco ejecuciones y un conteo por fila sería un N+1.
     *
     * @param int[] $runIds Ids de ejecución.
     * @return array<int, int> id de ejecución => nº de avisos.
     */
    public function countByRun(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.cronRun) AS run_id, COUNT(l.id) AS n')
            ->where('l.cronRun IN (:ids)')
            ->setParameter('ids', $runIds)
            ->groupBy('l.cronRun')
            ->getQuery()
            ->getScalarResult();

        $byRun = [];
        foreach ($rows as $row) {
            $byRun[(int) $row['run_id']] = (int) $row['n'];
        }

        return $byRun;
    }

    /**
     * Borra las líneas anteriores a una fecha y devuelve cuántas. La bitácora
     * crece con cada aviso y nadie va a consultar los de hace dos años; sin
     * purga, la tabla es la única del sistema que no para de subir.
     */
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.sentAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * Aplica los filtros comunes al listado y al resumen: si los dos no filtran
     * exactamente igual, los indicadores de cabecera cuentan una cosa y la tabla
     * enseña otra.
     *
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['from'])) {
            $qb->andWhere('l.sentAt >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['until'])) {
            $qb->andWhere('l.sentAt < :until')->setParameter('until', $filters['until']);
        }
        if (!empty($filters['channel'])) {
            $qb->andWhere('l.channel = :channel')->setParameter('channel', $filters['channel']);
        }
        if (!empty($filters['kind'])) {
            $qb->andWhere('l.kind = :kind')->setParameter('kind', $filters['kind']);
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('l.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['run'])) {
            $qb->andWhere('IDENTITY(l.cronRun) = :run')->setParameter('run', $filters['run']);
        }

        // La búsqueda libre mira a dónde fue y qué decía, más el nombre de la
        // persona cuando el aviso la conoce: quien diagnostica escribe el correo
        // que le acaban de dar, o el apellido, y espera que salga.
        if (!empty($filters['q'])) {
            $qb->andWhere('l.target LIKE :q OR l.subject LIKE :q OR p.name LIKE :q OR p.surname LIKE :q')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }
    }
}
