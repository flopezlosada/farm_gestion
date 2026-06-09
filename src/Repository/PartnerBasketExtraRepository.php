<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Overrides puntuales de cesta extra. Fuente de verdad de "esta semana este socio lleva N
 * de más de este componente", que la proyección suma al dibujar y el editor cascadea a la
 * piedra. Ver {@see PartnerBasketExtra} y docs/redesign/modelo-overrides-reparto.md.
 *
 * @extends ServiceEntityRepository<PartnerBasketExtra>
 */
class PartnerBasketExtraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerBasketExtra::class);
    }

    /**
     * El extra de un socio en una semana para un componente concreto, o null. Lo usa el
     * editor para crear o re-apuntar (upsert) sin duplicar.
     *
     * @param Partner         $partner
     * @param Basket          $basket
     * @param BasketComponent $component
     * @return PartnerBasketExtra|null
     */
    public function findOneForPartnerBasketComponent(Partner $partner, Basket $basket, BasketComponent $component): ?PartnerBasketExtra
    {
        return $this->findOneBy(['partner' => $partner, 'basket' => $basket, 'component' => $component]);
    }

    /**
     * Todos los extras de un socio en una semana (todos los componentes). Para cascadear a
     * la piedra y para deshacer.
     *
     * @param Partner $partner
     * @param Basket  $basket
     * @return PartnerBasketExtra[]
     */
    public function findForPartnerAndBasket(Partner $partner, Basket $basket): array
    {
        return $this->findBy(['partner' => $partner, 'basket' => $basket]);
    }

    /**
     * Todos los extras de una semana, indexados por partner.id => [componentId => amount(float)].
     * Lo consume la proyección para sumar el delta al dibujar cada entrega (o crearla si al
     * socio no le tocaba). Volumen pequeño (overrides puntuales), sin paginación.
     *
     * @param Basket $basket
     * @return array<int, array<int, float>> partner.id => (componentId => cantidad adicional)
     */
    public function extrasByPartnerForBasket(Basket $basket): array
    {
        $rows = $this->createQueryBuilder('e')
            ->innerJoin('e.partner', 'p')
            ->innerJoin('e.component', 'c')
            ->select('IDENTITY(e.partner) AS partner_id', 'IDENTITY(e.component) AS component_id', 'e.amount AS amount')
            ->where('e.basket = :basket')
            ->setParameter('basket', $basket)
            ->getQuery()
            ->getArrayResult();

        $byPartner = [];
        foreach ($rows as $row) {
            $byPartner[(int) $row['partner_id']][(int) $row['component_id']] = (float) $row['amount'];
        }

        return $byPartner;
    }
}
