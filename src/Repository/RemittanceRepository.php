<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Remittance>
 */
class RemittanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Remittance::class);
    }

    /**
     * Todas, la más reciente primero, con sus recibos cargados para sumar el total sin
     * una consulta por remesa.
     *
     * @return list<Remittance>
     */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.lines', 'l')->addSelect('l')
            ->orderBy('r.period', 'DESC')
            ->addOrderBy('r.kind', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las remesas enviadas con día de cargo: las que se vigila que entren en el banco.
     * Sin sus recibos: el total sólo hace falta de las que no han entrado, que son
     * pocas o ninguna.
     *
     * @return list<Remittance>
     */
    public function findSentWithChargeDate(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :sent')->setParameter('sent', Remittance::STATUS_SENT)
            ->andWhere('r.chargeDate IS NOT NULL')
            ->orderBy('r.chargeDate', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La última remesa de un tipo, o la última anterior a un mes: de la que sale la
     * remesa de ese mes, y contra la que se ven sus cambios.
     *
     * Sin traerse los recibos en la misma consulta: con un LIMIT 1, un JOIN de la
     * colección cortaría las filas y la remesa llegaría con un solo recibo.
     */
    public function findLatestOfKind(string $kind, ?\DateTimeInterface $before = null): ?Remittance
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.kind = :kind')->setParameter('kind', $kind);
        if ($before !== null) {
            $qb->andWhere('r.period < :before')->setParameter('before', Remittance::firstOfMonth($before));
        }

        return $qb
            ->orderBy('r.period', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Carga de una vez los recibos de unas remesas con su socix y quien encabeza su
     * familia, que es lo que mira la revisión y pinta la ficha. Sin esto, cada recibo
     * pedía su socix y su principal en una consulta aparte.
     *
     * No devuelve nada: rellena las remesas que ya se tienen. Las nulas se ignoran
     * (la primera de su tipo no tiene anterior).
     */
    public function loadLinesWithPartners(?Remittance ...$remittances): void
    {
        $ids = array_values(array_filter(array_map(static fn (?Remittance $r): ?int => $r?->getId(), $remittances)));
        if ($ids === []) {
            return;
        }

        $this->createQueryBuilder('r')
            ->leftJoin('r.lines', 'l')->addSelect('l')
            ->leftJoin('l.partner', 'p')->addSelect('p')
            ->leftJoin('p.parent', 'head')->addSelect('head')
            ->andWhere('r.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /** El mes de la remesa más antigua: desde ahí se llevan las remesas aquí. */
    public function firstPeriod(): ?\DateTimeImmutable
    {
        $first = $this->createQueryBuilder('r')
            ->select('MIN(r.period)')
            ->getQuery()
            ->getSingleScalarResult();

        return $first !== null ? new \DateTimeImmutable((string) $first) : null;
    }

    public function existsFor(string $kind, \DateTimeInterface $period): bool
    {
        return $this->count(['kind' => $kind, 'period' => Remittance::firstOfMonth($period)]) > 0;
    }

    /** Prefijo de los mandatos nuevos cuando no hay otro del que partir. */
    public const DEFAULT_MANDATE_PREFIX = 'CUOTA MENSUAL';

    /**
     * El número siguiente para un mandato nuevo con ese prefijo («CUOTA MENSUAL 214»):
     * uno más que el mayor usado en cualquier remesa o ficha de socix. Nunca rellena
     * huecos, porque el banco prohíbe reutilizar la referencia de un mandato, aunque
     * sea de una baja.
     */
    public function nextMandateReference(string $prefix = self::DEFAULT_MANDATE_PREFIX): string
    {
        $prefix = rtrim($prefix) . ' ';
        $like = addcslashes($prefix, '%_') . '%';
        $used = fn (string $entity, string $field): array => $this->getEntityManager()->createQueryBuilder()
            ->select(sprintf('DISTINCT e.%s', $field))
            ->from($entity, 'e')
            ->andWhere(sprintf('e.%s LIKE :prefix', $field))->setParameter('prefix', $like)
            ->getQuery()
            ->getSingleColumnResult();
        $references = [...$used(RemittanceLine::class, 'mandateReference'), ...$used(Partner::class, 'mandateReference')];

        $numbers = array_map(
            static fn (string $ref): int => (int) substr($ref, \strlen($prefix)),
            array_filter($references, static fn (string $ref): bool => ctype_digit(substr($ref, \strlen($prefix)))),
        );

        return $prefix . (($numbers === [] ? 0 : max($numbers)) + 1);
    }
}
