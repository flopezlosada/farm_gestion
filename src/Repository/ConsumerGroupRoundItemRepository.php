<?php

namespace App\Repository;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRoundItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Productos incluidos en cada ronda (con su precio de ronda).
 *
 * @extends ServiceEntityRepository<ConsumerGroupRoundItem>
 */
class ConsumerGroupRoundItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupRoundItem::class);
    }

    /**
     * Histórico de un producto del catálogo a través de las rondas en las que ha
     * entrado: una fila por ronda, con su precio de esa ronda y la ronda cargada
     * (para pintar fecha/estado). Es la base del histórico de precios Y de
     * consumo de la ficha del producto —ambos son la misma serie temporal, solo
     * cambia qué columna se mira—, así que es una sola query, no dos.
     *
     * Cualquier estado (no solo confirmadas): el histórico de PRECIOS interesa
     * aunque la ronda no llegara a confirmarse, y es la propia ficha la que
     * distingue qué ronda es "consumo real" pintando su estado/confirmación.
     *
     * @return ConsumerGroupRoundItem[] más reciente primero
     */
    public function findHistoryForProduct(ConsumerGroupProduct $product): array
    {
        return $this->createQueryBuilder('ri')
            ->join('ri.round', 'r')->addSelect('r')
            ->join('r.producer', 'p')->addSelect('p')
            ->where('ri.product = :product')
            ->setParameter('product', $product)
            ->orderBy('r.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * El precio que tuvo este producto la ÚLTIMA vez que entró en una ronda
     * (de cualquier estado: sirve de arranque, no de dato "real"). Es el valor
     * con el que se puebla una ronda nueva ({@see \App\Service\ConsumerGroup\RoundItemEditor::seedFromCatalog()}):
     * ya no hay precio de referencia en el catálogo, así que el mejor arranque
     * es lo último que se cobró por él. Null si nunca ha entrado en ninguna.
     */
    public function findLastPriceForProduct(ConsumerGroupProduct $product): ?string
    {
        $price = $this->createQueryBuilder('ri')
            ->select('ri.price')
            ->join('ri.round', 'r')
            ->where('ri.product = :product')
            ->setParameter('product', $product)
            ->orderBy('r.created', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $price === null ? null : $price['price'];
    }
}
