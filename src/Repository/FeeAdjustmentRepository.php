<?php

namespace App\Repository;

use App\Entity\DonationInitiative;
use App\Entity\FeeAdjustment;
use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeeAdjustment>
 */
class FeeAdjustmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeeAdjustment::class);
    }

    /**
     * Los de un socix, el que empieza más tarde primero.
     *
     * @return list<FeeAdjustment>
     */
    public function findForPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.initiative', 'i')->addSelect('i')
            ->andWhere('a.partner = :partner')->setParameter('partner', $partner)
            ->orderBy('a.fromMonth', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todos los que valen en un mes, de todo el mundo, en una consulta: lo que
     * necesita el cálculo de las cuotas de ese mes.
     *
     * @return list<FeeAdjustment>
     */
    public function findApplyingTo(\DateTimeInterface $month): array
    {
        $month = FeeAdjustment::month($month);

        return $this->createQueryBuilder('a')
            ->join('a.partner', 'p')->addSelect('p')
            ->leftJoin('a.initiative', 'i')->addSelect('i')
            ->andWhere('a.fromMonth <= :month')
            ->andWhere('a.untilMonth IS NULL OR a.untilMonth >= :month')
            ->setParameter('month', $month)
            ->getQuery()
            ->getResult();
    }

    /**
     * Lo que recibe cada iniciativa en un mes: cuántas personas aportan y cuánto suman.
     *
     * @return array<int, array{people: int, cents: int}> Por id de iniciativa.
     */
    public function totalsByInitiative(\DateTimeInterface $month): array
    {
        $totals = [];
        foreach ($this->findApplyingTo($month) as $adjustment) {
            $initiative = $adjustment->getInitiative();
            if ($initiative === null) {
                continue;
            }
            $id = $initiative->getId();
            $totals[$id] = [
                'people' => ($totals[$id]['people'] ?? 0) + 1,
                'cents' => ($totals[$id]['cents'] ?? 0) + IssuedInvoice::toCents($adjustment->getAmount()),
            ];
        }

        return $totals;
    }

    /** Si una iniciativa ha recibido alguna aportación: entonces no se borra, se cierra. */
    public function isUsedBy(DonationInitiative $initiative): bool
    {
        return $this->count(['initiative' => $initiative]) > 0;
    }
}
