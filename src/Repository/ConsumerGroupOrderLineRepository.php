<?php

namespace App\Repository;

use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
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

    /**
     * Lo último que pidió la familia de cada uno de estos productos, en una sola
     * consulta: producto => cantidad de la vez más reciente (por fecha de entrega) en
     * que pidió algo de él. Casa por PRODUCTO del catálogo, no por línea: cada pedido
     * tiene sus propios items. Solo cuentan cantidades > 0 (un pedido vaciado no
     * propone nada) y se excluye el pedido en curso.
     *
     * @param list<int> $productIds productos del catálogo por los que se pregunta
     *
     * @return array<int, string> id del producto => cantidad
     */
    public function findLastQuantitiesByProduct(Partner $partner, array $productIds, ?int $exceptRoundId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('l')
            ->select('IDENTITY(ri.product) AS productId', 'l.quantity AS quantity')
            ->join('l.order', 'o')
            ->join('l.roundItem', 'ri')
            ->join('o.round', 'r')
            ->where('o.partner = :partner')
            ->andWhere('ri.product IN (:products)')
            ->andWhere('l.quantity > 0')
            ->setParameter('partner', $partner->getFamilyHead())
            ->setParameter('products', $productIds)
            ->orderBy('r.deliveryDate', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if (null !== $exceptRoundId) {
            $qb->andWhere('r.id <> :except')->setParameter('except', $exceptRoundId);
        }

        $quantities = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            // Orden descendente: la primera vez que sale un producto es la más reciente.
            $quantities[(int) $row['productId']] ??= (string) $row['quantity'];
        }

        return $quantities;
    }
}
