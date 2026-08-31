<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Las consultas de la bandeja de avisos: lo que hay que enseñar y cuántos quedan
 * sin abrir.
 *
 * NO HAY PURGA, a diferencia de lo que suele llevar una tabla que sólo crece: son
 * unos pocos avisos por socix y semana —del orden de diez mil filas al año para
 * los 246—, y el histórico de qué se le avisó a quién es justo lo que se quiere
 * poder mirar cuando alguien dice que no le llegó nada. Si algún día molesta, el
 * sitio de la política es un comando del planificador, no este repositorio.
 *
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    /**
     * Cuántos avisos como mucho enseña la bandeja de una vez.
     *
     * Hay tope y no paginación a propósito: un aviso es un empujón con fecha de
     * caducidad práctica, no un archivo que se consulte hacia atrás. Quien baja
     * más de cincuenta avisos ya no está buscando lo que le toca esta semana.
     */
    public const INBOX_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Los avisos de una cuenta, el más reciente primero.
     *
     * Ordena además por id descendente porque varios avisos de la misma tanda del
     * planificador comparten el segundo exacto de creación: sin el desempate, dos
     * cargas de la misma pantalla podrían enseñarlos en distinto orden.
     *
     * @param User $user  la cuenta que los recibe
     * @param int  $limit cuántos como mucho
     *
     * @return list<Notification> los avisos, el más reciente primero
     */
    public function findRecentFor(User $user, int $limit = self::INBOX_LIMIT): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :user')->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuántos avisos tiene sin abrir una cuenta. Es el número de la campanita.
     *
     * @param User $user la cuenta
     *
     * @return int cuántos sin abrir
     */
    public function countUnreadFor(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :user')->setParameter('user', $user)
            ->andWhere('n.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
