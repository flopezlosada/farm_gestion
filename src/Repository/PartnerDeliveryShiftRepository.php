<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\BasketComponent;
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
     * Localiza el cambio puntual de ENTREGA ENTERA que un socio tiene saliendo de
     * un Basket (si lo hay). Equivalente a "¿se va este socio de este viernes a
     * otro?". Acotado a `component IS NULL`: los intents POR COMPONENTE (mover solo
     * la verdura) no son un cambio de la entrega completa y, además, puede haber
     * varios desde el mismo Basket — se consultan con findComponentIntentsFromBasket.
     */
    public function findOutgoing(Partner $partner, Basket $from): ?PartnerDeliveryShift
    {
        // LIMIT 1 defensivo: la unicidad la garantiza la BBDD (component_key), pero una
        // fila sucia HISTÓRICA (anterior a la constraint) no debe volver a tumbar el
        // calendario con NonUniqueResultException — se devuelve una, la más antigua.
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner AND s.fromBasket = :from AND s.component IS NULL')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Localiza el cambio puntual de ENTREGA ENTERA que un socio tiene entrando a un
     * Basket (si lo hay). Acotado a `component IS NULL` (ver findOutgoing).
     */
    public function findIncoming(Partner $partner, Basket $to): ?PartnerDeliveryShift
    {
        // LIMIT 1 defensivo, misma razón que findOutgoing.
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner AND s.toBasket = :to AND s.component IS NULL')
            ->setParameter('partner', $partner)
            ->setParameter('to', $to)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Intents POR COMPONENTE (component IS NOT NULL) que SALEN de un Basket para un
     * socio: ese componente deja de recogerse esa semana (o se mueve a otra). Puede
     * haber varios (verdura y huevos por separado), de ahí que devuelva lista.
     *
     * @return PartnerDeliveryShift[]
     */
    public function findComponentIntentsFromBasket(Partner $partner, Basket $from): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner AND s.fromBasket = :from AND s.component IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->getQuery()
            ->getResult();
    }

    /**
     * Intents POR COMPONENTE (component IS NOT NULL) que ENTRAN a un Basket para un
     * socio: ese componente se añade a esa semana (viene movido de otra).
     *
     * @return PartnerDeliveryShift[]
     */
    public function findComponentIntentsToBasket(Partner $partner, Basket $to): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner AND s.toBasket = :to AND s.component IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * Intent de un COMPONENTE concreto que entra a un Basket para un socio (si lo
     * hay). Único por (partner, to, component), así que devuelve uno o null. Se usa
     * para resolver el origen de patrón al mover un componente que ya venía movido.
     */
    public function findComponentIncoming(Partner $partner, Basket $to, BasketComponent $component): ?PartnerDeliveryShift
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner AND s.toBasket = :to AND s.component = :component')
            ->setParameter('partner', $partner)
            ->setParameter('to', $to)
            ->setParameter('component', $component)
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
            ->where('s.partner = :partner AND s.component IS NULL AND (s.fromBasket = :basket OR s.toBasket = :basket)')
            ->setParameter('partner', $partner)
            ->setParameter('basket', $basket)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
