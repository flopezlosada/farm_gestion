<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserEvent>
 */
class UserEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEvent::class);
    }

    /**
     * Eventos de una cuenta en orden cronológico inverso (lo más reciente arriba).
     *
     * @param User     $user  Cuenta cuyos eventos se buscan.
     * @param int|null $limit Máximo de eventos a devolver; sin límite si null.
     * @return UserEvent[] Eventos ordenados de más reciente a más antiguo.
     */
    public function findForUser(User $user, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.created', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }
}
