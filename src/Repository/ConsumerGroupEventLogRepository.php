<?php

namespace App\Repository;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupRound;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consultas sobre la bitácora de eventos del grupo de consumo ({@see ConsumerGroupEventLog}).
 *
 * @extends ServiceEntityRepository<ConsumerGroupEventLog>
 */
class ConsumerGroupEventLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupEventLog::class);
    }

    /**
     * La actividad de un pedido, de más nueva a más vieja.
     *
     * @return ConsumerGroupEventLog[]
     */
    public function findByRound(ConsumerGroupRound $round): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.actorUser', 'u')->addSelect('u')
            ->where('e.round = :round')
            ->setParameter('round', $round)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Actividad de UN actor concreto, de más nueva a más vieja. Pensado para el
     * productor autogestionado: su login y las altas/ediciones/bajas de su
     * catálogo quedan con `actorUser` = su propio User, así que esto es "lo que
     * ha hecho este productor" sin necesitar una FK propia a Producer.
     *
     * @return ConsumerGroupEventLog[]
     */
    public function findByActor(User $actor): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.actorUser = :actor')
            ->setParameter('actor', $actor)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
