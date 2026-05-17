<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerDeliveryShift>
 */
class PartnerDeliveryShiftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerDeliveryShift::class);
    }

    /**
     * Localiza el cambio puntual que un socio tiene saliendo de un Basket
     * concreto (si lo hay). Equivalente a la pregunta "¿se va este socio
     * de este viernes a otro?".
     */
    public function findOutgoing(Partner $partner, Basket $from): ?PartnerDeliveryShift
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner AND s.fromBasket = :from')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Localiza el cambio puntual que un socio tiene entrando a un Basket
     * concreto (si lo hay). Equivalente a "¿este socio recoge aquí porque
     * ha pedido cambio desde otro viernes?".
     */
    public function findIncoming(Partner $partner, Basket $to): ?PartnerDeliveryShift
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner AND s.toBasket = :to')
            ->setParameter('partner', $partner)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todos los cambios que salen de un Basket (el generador los suprime
     * del listado de ese viernes).
     *
     * @return PartnerDeliveryShift[]
     */
    public function findAllOutgoingFromBasket(Basket $from): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.fromBasket = :from')
            ->setParameter('from', $from)
            ->getQuery()
            ->getResult();
    }

    /**
     * Todos los cambios que entran a un Basket (el generador los añade al
     * listado de ese viernes).
     *
     * @return PartnerDeliveryShift[]
     */
    public function findAllIncomingToBasket(Basket $to): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.toBasket = :to')
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cambio puntual que afecta a un Basket dado (saliendo o entrando)
     * para un socio concreto. Devuelve el shift relevante para mostrar
     * en el panel del socix cuando le toca la próxima cesta.
     */
    public function findAffectingPartnerAtBasket(Partner $partner, Basket $basket): ?PartnerDeliveryShift
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->andWhere('s.fromBasket = :basket OR s.toBasket = :basket')
            ->setParameter('partner', $partner)
            ->setParameter('basket', $basket)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
