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
     * Cestas extra VIGENTES (semana >= $from) de un socio, agrupadas por semana para
     * pintarlas en su ficha con opción de quitar. Las pasadas se omiten: ya no se
     * pueden deshacer y sólo ensucian. Cada entrada trae la fecha del Basket y las
     * cantidades por componente.
     *
     * @param Partner            $partner
     * @param \DateTimeInterface $from Fecha desde la que listar (inclusive).
     * @return list<array{basketId:int, date:?\DateTimeInterface, vegetables:float, eggs:float}>
     */
    public function upcomingForPartner(Partner $partner, \DateTimeInterface $from): array
    {
        $rows = $this->createQueryBuilder('e')
            ->innerJoin('e.basket', 'b')
            ->select('IDENTITY(e.basket) AS basket_id', 'b.date AS date', 'IDENTITY(e.component) AS component_id', 'e.amount AS amount')
            ->where('e.partner = :partner')
            ->andWhere('b.date >= :from')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byBasket = [];
        foreach ($rows as $row) {
            $id = (int) $row['basket_id'];
            $byBasket[$id] ??= ['basketId' => $id, 'date' => $row['date'], 'vegetables' => 0.0, 'eggs' => 0.0];
            if ((int) $row['component_id'] === BasketComponent::ID_VEGETABLES) {
                $byBasket[$id]['vegetables'] += (float) $row['amount'];
            } elseif ((int) $row['component_id'] === BasketComponent::ID_EGGS) {
                $byBasket[$id]['eggs'] += (float) $row['amount'];
            }
        }

        return array_values($byBasket);
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
