<?php

namespace App\Repository;

use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupRoundItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Líneas de los pedidos del grupo de consumo.
 *
 * @extends ServiceEntityRepository<ConsumerGroupOrderLine>
 */
class ConsumerGroupOrderLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupOrderLine::class);
    }

    /**
     * Líneas con cantidad pedida (> 0) de este item de ronda, con la socia
     * cargada. Para saber a quién le afecta quitar un producto de una ronda
     * antes de quitarlo.
     *
     * @return ConsumerGroupOrderLine[]
     */
    public function findWithQuantityForItem(ConsumerGroupRoundItem $item): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.order', 'o')->addSelect('o')
            ->join('o.partner', 'p')->addSelect('p')
            ->where('l.roundItem = :item')
            ->andWhere('l.quantity > 0')
            ->setParameter('item', $item)
            ->getQuery()
            ->getResult();
    }
}
