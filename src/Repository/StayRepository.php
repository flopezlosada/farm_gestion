<?php

namespace App\Repository;

use App\Entity\Stay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Stay|null find($id, $lockMode = null, $lockVersion = null)
 * @method Stay|null findOneBy(array $criteria, array $orderBy = null)
 * @method Stay[]    findAll()
 * @method Stay[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stay::class);
    }

    /**
     * Estancias CONFIRMADAS que solapan con el rango [$from, $to) — es decir,
     * las que ocupan al menos un día de ese rango. Convención de medio intervalo
     * abierto, coherente con {@see \App\Service\Hosting\OccupancyChecker}: dos
     * estancias se tocan si A.arrival < B.departure && A.departure > B.arrival.
     *
     * Sirve para alimentar al OccupancyChecker con las estancias relevantes sin
     * traer toda la tabla. Sólo confirmadas: las solicitadas/canceladas no
     * comprometen aforo.
     *
     * @param \DateTimeImmutable $from        Inicio del rango (incluido).
     * @param \DateTimeImmutable $to          Fin del rango (excluido).
     * @param int|null           $excludeId   Estancia a excluir (la que se está
     *                                         editando, para no contarse a sí misma).
     * @return Stay[]
     */
    public function findConfirmedOverlapping(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $excludeId = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->andWhere('s.arrivalDate < :to')
            ->andWhere('s.departureDate > :from')
            ->setParameter('status', Stay::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.arrivalDate', 'ASC');

        if ($excludeId !== null) {
            $qb->andWhere('s.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Estancias de los estados dados que solapan con el rango [$from, $to), con
     * su voluntario traído en el mismo SELECT (el timeline pinta una fila por
     * persona, así que sin esto sería N+1). Por defecto confirmadas y
     * solicitadas; las canceladas no se muestran en el calendario.
     *
     * @param \DateTimeImmutable $from     Inicio del rango (incluido).
     * @param \DateTimeImmutable $to       Fin del rango (excluido).
     * @param string[]           $statuses Estados a incluir.
     * @return Stay[] Ordenadas por voluntario y llegada.
     */
    public function findOverlapping(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $statuses = [Stay::STATUS_CONFIRMED, Stay::STATUS_REQUESTED],
    ): array {
        return $this->createQueryBuilder('s')
            ->addSelect('h')
            ->join('s.helper', 'h')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.arrivalDate < :to')
            ->andWhere('s.departureDate > :from')
            ->setParameter('statuses', $statuses)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('h.name', 'ASC')
            ->addOrderBy('s.arrivalDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Estancias cuya LLEGADA cae en el año dado, con su voluntario y la
     * procedencia traídos en el mismo SELECT (las métricas agrupan por
     * procedencia, sin esto sería N+1). Por defecto confirmadas y solicitadas;
     * las canceladas no cuentan para métricas.
     *
     * @param int      $year     Año de la llegada.
     * @param string[] $statuses Estados a incluir.
     * @return Stay[]
     */
    public function findByArrivalYear(
        int $year,
        array $statuses = [Stay::STATUS_CONFIRMED, Stay::STATUS_REQUESTED],
    ): array {
        return $this->createQueryBuilder('s')
            ->addSelect('h', 'src')
            ->join('s.helper', 'h')
            ->join('h.source', 'src')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.arrivalDate >= :from')
            ->andWhere('s.arrivalDate < :to')
            ->setParameter('statuses', $statuses)
            ->setParameter('from', new \DateTimeImmutable(sprintf('%04d-01-01', $year)))
            ->setParameter('to', new \DateTimeImmutable(sprintf('%04d-01-01', $year + 1)))
            ->getQuery()
            ->getResult();
    }

    /**
     * Estancias CONFIRMADAS que LLEGAN en [$from, $to), con su voluntario, para
     * el recordatorio de llegadas. Ordenadas por fecha de llegada.
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @return Stay[]
     */
    public function findArrivalsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->byDateField('arrivalDate', $from, $to);
    }

    /**
     * Estancias CONFIRMADAS que SALEN en [$from, $to), con su voluntario, para el
     * recordatorio de salidas. Ordenadas por fecha de salida.
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @return Stay[]
     */
    public function findDeparturesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->byDateField('departureDate', $from, $to);
    }

    /**
     * Estancias confirmadas cuyo campo de fecha indicado cae en [$from, $to),
     * con el voluntario en el mismo SELECT. Helper privado de
     * {@see self::findArrivalsBetween()} / {@see self::findDeparturesBetween()}.
     *
     * @param string             $field 'arrivalDate' o 'departureDate'.
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @return Stay[]
     */
    private function byDateField(string $field, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('h')
            ->join('s.helper', 'h')
            ->andWhere('s.status = :status')
            ->andWhere(sprintf('s.%s >= :from', $field))
            ->andWhere(sprintf('s.%s < :to', $field))
            ->setParameter('status', Stay::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.' . $field, 'ASC')
            ->getQuery()
            ->getResult();
    }
}
