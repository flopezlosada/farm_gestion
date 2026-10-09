<?php

namespace App\Repository;

use App\Entity\TelegramLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramLink>
 */
class TelegramLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramLink::class);
    }

    /**
     * El vínculo completo de una cuenta de Telegram: quién manda lo que llega.
     *
     * @param string $telegramUserId Id de usuario de Telegram.
     */
    public function findLinked(string $telegramUserId): ?TelegramLink
    {
        return $this->findOneBy(['telegramUserId' => $telegramUserId]);
    }

    /**
     * La invitación con ese código, caducada o no: quien llama decide qué contestar.
     *
     * @param string $code Código del enlace.
     */
    public function findByInvitationCode(string $code): ?TelegramLink
    {
        return $this->findOneBy(['invitationCode' => $code]);
    }

    /**
     * El vínculo (o la invitación) de una persona.
     *
     * @param User $user Persona.
     */
    public function findForUser(User $user): ?TelegramLink
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Todos, con su persona, para el listado.
     *
     * @return list<TelegramLink>
     */
    public function findAllWithUser(): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('u')
            ->join('l.user', 'u')
            ->orderBy('l.linkedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
