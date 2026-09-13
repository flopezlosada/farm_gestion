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
     * Cambios de MODALIDAD que los dos hogares acordaron y que aún no se han aplicado.
     *
     * Una de éstas está viva aunque su estado diga "aceptada": aceptar no la aplica —lleva
     * cuota detrás y la aplica administración—, así que hasta entonces sigue en curso. Se
     * sabe comparando lo acordado con la cesta que la pareja tiene hoy: si ya es esa, el
     * acuerdo se cumplió y pasa a ser historia.
     *
     * @param Partner  $one        Un hogar.
     * @param Partner  $other      El otro.
     * @param int|null $currentId  Id de la modalidad que tienen hoy.
     *
     * @return SharedBasketChangeRequest[]
     */
    public function findAgreedModalityPending(Partner $one, Partner $other, ?int $currentId): array
    {
        $agreed = $this->createQueryBuilder('r')
            ->innerJoin('r.requester', 'p')
            ->innerJoin('r.counterpart', 'cp')
            ->addSelect('p', 'cp')
            ->where('r.kind = :modality')
            ->andWhere('r.status = :accepted')
            ->andWhere('(r.requester = :one AND r.counterpart = :other) OR (r.requester = :other AND r.counterpart = :one)')
            ->setParameter('modality', SharedBasketChangeRequest::KIND_MODALITY)
            ->setParameter('accepted', SharedBasketChangeRequest::STATUS_ACCEPTED)
            ->setParameter('one', $one)
            ->setParameter('other', $other)
            ->orderBy('r.decidedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $agreed,
            static fn (SharedBasketChangeRequest $r): bool => ($r->getPayload()['basket_share_id'] ?? null) !== $currentId
        ));
    }

    /**
     * Lo ya contestado hace poco entre los dos hogares, de lo más reciente a lo más viejo.
     *
     * Existe por el aviso de la respuesta: quien recibe "no puedo con ese cambio" abre la
     * pantalla y, si sólo se pintara lo pendiente, se encontraría la nada. Un desenlace es
     * justo lo que hay que poder leer ahí.
     *
     * @param Partner            $one   Un hogar.
     * @param Partner            $other El otro.
     * @param \DateTimeImmutable $since Desde cuándo se considera reciente.
     *
     * @return SharedBasketChangeRequest[]
     */
    public function findDecidedBetween(Partner $one, Partner $other, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.requester', 'p')
            ->innerJoin('r.counterpart', 'cp')
            ->leftJoin('r.basket', 'b')
            ->leftJoin('r.toBasket', 'tb')
            ->addSelect('p', 'cp', 'b', 'tb')
            ->where('r.status <> :pending')
            ->andWhere('r.decidedAt >= :since')
            ->andWhere('(r.requester = :one AND r.counterpart = :other) OR (r.requester = :other AND r.counterpart = :one)')
            ->setParameter('pending', SharedBasketChangeRequest::STATUS_PENDING)
            ->setParameter('since', $since)
            ->setParameter('one', $one)
            ->setParameter('other', $other)
            ->orderBy('r.decidedAt', 'DESC')
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
