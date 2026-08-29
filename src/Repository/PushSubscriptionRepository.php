<?php

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * Todos los navegadores suscritos de una persona.
     *
     * @param User $user la persona
     *
     * @return list<PushSubscription> sus suscripciones
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /**
     * La suscripción de un endpoint concreto, para que re-suscribirse actualice
     * la fila en vez de crear una nueva.
     *
     * @param string $endpoint la URL del servicio de push
     *
     * @return PushSubscription|null la suscripción, o null si es nueva
     */
    public function findOneByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    /**
     * Todos los navegadores suscritos de un grupo de personas, en UNA consulta.
     *
     * Es lo que permite que un aviso a doscientos socixs no sean doscientas
     * consultas: {@see \App\Service\Push\PushSender::sendToMany()} necesita
     * todas las suscripciones juntas para mandarlas en un único lote.
     *
     * @param list<User> $users las personas
     *
     * @return list<PushSubscription> las suscripciones de todas ellas
     */
    public function findByUsers(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->where('s.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();
    }
}
