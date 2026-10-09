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
     * Gasta la invitación sólo si sigue siendo la misma y no ha caducado, en una
     * sola sentencia: de dos peticiones simultáneas con el mismo código, sólo una
     * la gasta. Quien llama completa el vínculo después.
     *
     * @param TelegramLink       $link Invitación.
     * @param string             $code Código con que se abrió el enlace.
     * @param \DateTimeImmutable $now  Momento actual.
     *
     * @return bool Si la ha gastado esta petición.
     */
    public function spendInvitation(TelegramLink $link, string $code, \DateTimeImmutable $now): bool
    {
        $spent = $this->getEntityManager()->createQuery(
            'UPDATE ' . TelegramLink::class . ' l
             SET l.invitationCode = NULL, l.invitationExpiresAt = NULL
             WHERE l.id = :id AND l.invitationCode = :code AND l.invitationExpiresAt > :now'
        )
            ->setParameter('id', $link->getId())
            ->setParameter('code', $code)
            ->setParameter('now', $now)
            ->execute();

        return $spent === 1;
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
