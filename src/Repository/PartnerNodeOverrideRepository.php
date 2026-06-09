<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerNodeOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Overrides puntuales de nodo de recogida. Fuente de verdad de "esta semana este socio
 * recoge en otro nodo", que la proyección aplica al dibujar y el applier cascadea a la
 * piedra. Ver {@see PartnerNodeOverride} y docs/redesign/modelo-overrides-reparto.md.
 *
 * @extends ServiceEntityRepository<PartnerNodeOverride>
 */
class PartnerNodeOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerNodeOverride::class);
    }

    /**
     * Override de nodo de un socio en una semana concreta, o null si no tiene.
     *
     * @param Partner $partner
     * @param Basket  $basket
     * @return PartnerNodeOverride|null
     */
    public function findForPartnerAndBasket(Partner $partner, Basket $basket): ?PartnerNodeOverride
    {
        return $this->findOneBy(['partner' => $partner, 'basket' => $basket]);
    }

    /**
     * Todos los overrides de nodo de una semana. La proyección los reparte: los que salen
     * del nodo de casa (excluir de su nodo) y los que entran a un nodo (incluir como
     * trasladados). Volumen pequeño (overrides puntuales), sin paginación.
     *
     * @param Basket $basket
     * @return PartnerNodeOverride[]
     */
    public function findAllForBasket(Basket $basket): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.partner', 'p')
            ->innerJoin('o.targetGroup', 'g')
            ->addSelect('p', 'g')
            ->where('o.basket = :basket')
            ->setParameter('basket', $basket)
            ->getQuery()
            ->getResult();
    }
}
