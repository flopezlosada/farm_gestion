<?php

namespace App\Repository;

use App\Entity\WeeklyBasketGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method WeeklyBasketGroup|null find($id, $lockMode = null, $lockVersion = null)
 * @method WeeklyBasketGroup|null findOneBy(array $criteria, array $orderBy = null)
 * @method WeeklyBasketGroup[]    findAll()
 * @method WeeklyBasketGroup[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WeeklyBasketGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyBasketGroup::class);
    }

    /**
     * QueryBuilder del listado de grupos con filtros opcionales, para que el
     * controller delegue la paginación al PaginatorInterface de Knp. El
     * left-join con node trae el nodo en la misma query (evita N+1 al pintar
     * el chip de nodo de cada fila).
     *
     * @param array{q?: string|null, node?: int|string|null} $filters
     *        node: id del nodo, o el literal 'none' para "sin nodo".
     */
    public function findFilteredQb(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.node', 'n')
            ->addSelect('n')
            ->orderBy('g.name', 'ASC');

        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(g.name) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($filters['q']) . '%');
        }

        if (($filters['node'] ?? null) === 'none') {
            $qb->andWhere('g.node IS NULL');
        } elseif (!empty($filters['node'])) {
            $qb->andWhere('n.id = :node')->setParameter('node', (int) $filters['node']);
        }

        return $qb;
    }

    // /**
    //  * @return WeeklyBasketGroup[] Returns an array of WeeklyBasketGroup objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?WeeklyBasketGroup
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
