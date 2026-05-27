<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryException>
 */
class DeliveryExceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryException::class);
    }

    /**
     * Localiza la excepción global (sin nodo) registrada para un ciclo.
     *
     * Una excepción global afecta a todos los nodos (cierre general:
     * Navidad, agosto…). Es la que consultan los flujos que aún no
     * distinguen nodo.
     *
     * @param Basket $basket Ciclo a comprobar.
     * @return DeliveryException|null Excepción global, o null si no la hay.
     */
    public function findGlobalForBasket(Basket $basket): ?DeliveryException
    {
        return $this->createQueryBuilder('e')
            ->where('e.basket = :basket')
            ->andWhere('e.node IS NULL')
            ->setParameter('basket', $basket)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Resuelve la excepción que aplica a un nodo en un ciclo, dando
     * prioridad a la específica del nodo sobre la global.
     *
     * @param Basket $basket Ciclo a comprobar.
     * @param Node $node Nodo cuyo reparto se está resolviendo.
     * @return DeliveryException|null La específica del nodo si existe; si
     *                                no, la global; null si ninguna.
     */
    public function findForBasketAndNode(Basket $basket, Node $node): ?DeliveryException
    {
        $matches = $this->createQueryBuilder('e')
            ->where('e.basket = :basket')
            ->andWhere('e.node = :node OR e.node IS NULL')
            ->setParameter('basket', $basket)
            ->setParameter('node', $node)
            ->getQuery()
            ->getResult();

        $global = null;
        foreach ($matches as $match) {
            if ($match->getNode() === null) {
                $global = $match;
                continue;
            }
            return $match;
        }

        return $global;
    }

    /**
     * Excepciones cuyo ciclo (o fecha trasladada) cae dentro del rango
     * dado. Útil para pintar el calendario de excepciones.
     *
     * @param \DateTimeInterface $from Inicio del rango.
     * @param \DateTimeInterface $to Fin del rango.
     * @return DeliveryException[]
     */
    public function findInRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.basket', 'b')
            ->where('b.date BETWEEN :from AND :to')
            ->orWhere('e.shiftedDate BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
