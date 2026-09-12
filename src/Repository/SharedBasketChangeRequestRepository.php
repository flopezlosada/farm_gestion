<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\SharedBasketChangeRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Peticiones de cambio entre los dos hogares de una cesta compartida.
 *
 * Los finders devuelven SIEMPRE lo pendiente sin filtrar por caducidad: si una
 * petición sigue viva lo decide {@see SharedBasketChangeRequest::isActionable()} al
 * leerla, porque el plazo depende de la fecha de hoy y una consulta que lo mezclara
 * tendría que reescribirse cada vez que cambie la regla del plazo.
 *
 * @extends ServiceEntityRepository<SharedBasketChangeRequest>
 */
class SharedBasketChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedBasketChangeRequest::class);
    }

    /**
     * Lo que este hogar tiene que contestar, de lo más reciente a lo más viejo.
     *
     * @param Partner $counterpart Hogar que decide.
     *
     * @return SharedBasketChangeRequest[]
     */
    public function findPendingFor(Partner $counterpart): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.requester', 'p')
            ->leftJoin('r.basket', 'b')
            ->leftJoin('r.toBasket', 'tb')
            ->addSelect('p', 'b', 'tb')
            ->where('r.counterpart = :partner')
            ->andWhere('r.status = :pending')
            ->setParameter('partner', $counterpart)
            ->setParameter('pending', SharedBasketChangeRequest::STATUS_PENDING)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lo que este hogar ha pedido y sigue sin respuesta.
     *
     * @param Partner $requester Hogar que pidió.
     *
     * @return SharedBasketChangeRequest[]
     */
    public function findPendingFrom(Partner $requester): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.counterpart', 'p')
            ->leftJoin('r.basket', 'b')
            ->leftJoin('r.toBasket', 'tb')
            ->addSelect('p', 'b', 'tb')
            ->where('r.requester = :partner')
            ->andWhere('r.status = :pending')
            ->setParameter('partner', $requester)
            ->setParameter('pending', SharedBasketChangeRequest::STATUS_PENDING)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todo lo que está pendiente entre los dos hogares, lo pidiera quien lo pidiera.
     *
     * Es lo que mira la pantalla antes de dejar pedir otra cosa: dos peticiones vivas
     * sobre la misma cesta son dos acuerdos distintos esperando, y el segundo en
     * aceptarse se encontraría el reparto ya movido por el primero.
     *
     * @param Partner $one   Un hogar.
     * @param Partner $other El otro.
     *
     * @return SharedBasketChangeRequest[]
     */
    public function findPendingBetween(Partner $one, Partner $other): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :pending')
            ->andWhere('(r.requester = :one AND r.counterpart = :other) OR (r.requester = :other AND r.counterpart = :one)')
            ->setParameter('pending', SharedBasketChangeRequest::STATUS_PENDING)
            ->setParameter('one', $one)
            ->setParameter('other', $other)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
