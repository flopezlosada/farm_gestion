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
     * Resuelve la excepción que aplica a un nodo en un ciclo, aplicando la
     * regla de precedencia de {@see resolvePrecedence()}: una cancelación
     * global es absoluta; en lo demás, la específica del nodo manda.
     *
     * @param Basket $basket Ciclo a comprobar.
     * @param Node $node Nodo cuyo reparto se está resolviendo.
     * @return DeliveryException|null La excepción que aplica, o null si ninguna.
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
        $nodeSpecific = null;
        foreach ($matches as $match) {
            if ($match->getNode() === null) {
                $global = $match;
            } else {
                $nodeSpecific = $match;
            }
        }

        return self::resolvePrecedence($global, $nodeSpecific);
    }

    /**
     * Regla de precedencia entre la excepción global (sin nodo) y la
     * específica de nodo para un mismo ciclo.
     *
     * Una CANCELACIÓN global (`shiftedDate` null) es absoluta: el cierre se
     * pone cuando no hay trabajadores (Semana Santa, Navidad, agosto) y por
     * tanto no hay verdura que repartir — no reparte nadie, ni un nodo con
     * override. En cambio un TRASLADO global ("todos reparten otro día") sí
     * lo puede pisar un override de nodo que prefiera una fecha distinta.
     *
     * @param DeliveryException|null $global Excepción global, o null.
     * @param DeliveryException|null $nodeSpecific Excepción del nodo, o null.
     * @return DeliveryException|null La que aplica.
     */
    public static function resolvePrecedence(?DeliveryException $global, ?DeliveryException $nodeSpecific): ?DeliveryException
    {
        if ($global !== null && $global->isCancelled()) {
            return $global;
        }

        return $nodeSpecific ?? $global;
    }

    /**
     * Busca la excepción registrada EXACTAMENTE para (basket, node), sin el
     * fallback a la global que aplica {@see findForBasketAndNode}. Sirve
     * para impedir duplicados al crear/editar: el UNIQUE(basket, node) no
     * es fiable en MySQL porque admite varios NULL en node_id.
     *
     * @param Basket $basket Ciclo.
     * @param Node|null $node Nodo concreto, o null para la excepción global.
     * @return DeliveryException|null La fila exacta, o null si no existe.
     */
    public function findOneExact(Basket $basket, ?Node $node): ?DeliveryException
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.basket = :basket')
            ->setParameter('basket', $basket);

        if ($node === null) {
            $qb->andWhere('e.node IS NULL');
        } else {
            $qb->andWhere('e.node = :node')->setParameter('node', $node);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Cuenta los cierres GLOBALES que CANCELAN el reparto en un rango de
     * ciclos, estrictamente entre dos fechas (extremos excluidos).
     *
     * "Global" = sin nodo (`node IS NULL`): afecta a todos los puntos de
     * reparto (festivo, puente, agosto). "Cancela" = `shiftedDate IS NULL`:
     * ese ciclo no hay entrega (un traslado a otro día NO cuenta, porque sí
     * hay reparto). Cada uno de estos cierres desplaza una semana la
     * alternancia quincenal A/B; lo consume {@see \App\Service\Delivery\BiweeklyCohortResolver}.
     *
     * @param \DateTimeInterface $after Extremo inferior excluido (típicamente el ancla).
     * @param \DateTimeInterface $before Extremo superior excluido (el ciclo objetivo).
     * @return int Número de cierres globales cancelados en (after, before).
     */
    public function countGlobalCancellationsBetween(\DateTimeInterface $after, \DateTimeInterface $before): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.basket', 'b')
            ->where('e.node IS NULL')
            ->andWhere('e.shiftedDate IS NULL')
            ->andWhere('b.date > :after')
            ->andWhere('b.date < :before')
            ->setParameter('after', $after->format('Y-m-d'))
            ->setParameter('before', $before->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();
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
