<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerEvent>
 */
class PartnerEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerEvent::class);
    }

    /**
     * Eventos de un socio en orden cronológico inverso (lo más reciente arriba).
     *
     * @return PartnerEvent[]
     */
    public function findForPartner(Partner $partner, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('e.occurredAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Cuenta los eventos de un tipo dado en un rango temporal. Base para
     * los gráficos de evolución mensual (altas y bajas por mes).
     */
    public function countByTypeInRange(string $type, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.type = :type')
            ->andWhere('e.occurredAt BETWEEN :from AND :to')
            ->setParameter('type', $type)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Eventos de cualquiera de los tipos dados ocurridos desde `since`,
     * más recientes arriba. Pensado para el resumen periódico al admin
     * (BASKET_SKIP / BASKET_UNSKIP / NODE_CHANGE / WEEK_SWAP).
     *
     * @param string[] $types
     * @return PartnerEvent[]
     */
    public function findByTypesSince(array $types, \DateTimeInterface $since): array
    {
        if (empty($types)) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->where('e.type IN (:types)')
            ->andWhere('e.occurredAt >= :since')
            ->setParameter('types', $types)
            ->setParameter('since', $since)
            ->orderBy('e.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
