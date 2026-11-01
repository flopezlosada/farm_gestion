<?php

namespace App\Repository;

use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\TransportRate;
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

    /**
     * Todos los precios, los de las cestas y los del transporte, listos para preguntar
     * por fechas: dos consultas de pocas filas.
     */
    public function schedule(): FeeSchedule
    {
        /** @var TransportRateRepository $transport */
        $transport = $this->getEntityManager()->getRepository(TransportRate::class);

        return new FeeSchedule($this->findAll(), $transport->findAllWithGroup());
    }

    public function existsFor(FeeConcept $concept, \DateTimeInterface $validFrom): bool
    {
        return $this->count(['concept' => $concept, 'validFrom' => \DateTimeImmutable::createFromInterface($validFrom)->setTime(0, 0)]) > 0;
    }
}
