<?php

namespace App\Repository;

use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Service\Partner\Fee\FeeSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeeRate>
 */
class FeeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeeRate::class);
    }

    /** Todos los precios en una sola consulta, listos para preguntar por fechas. */
    public function schedule(): FeeSchedule
    {
        return new FeeSchedule($this->findAll());
    }

    public function existsFor(FeeConcept $concept, \DateTimeInterface $validFrom): bool
    {
        return $this->count(['concept' => $concept, 'validFrom' => \DateTimeImmutable::createFromInterface($validFrom)->setTime(0, 0)]) > 0;
    }
}
