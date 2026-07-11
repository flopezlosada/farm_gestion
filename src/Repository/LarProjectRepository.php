<?php

namespace App\Repository;

use App\Entity\LarProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method LarProject|null find($id, $lockMode = null, $lockVersion = null)
 * @method LarProject|null findOneBy(array $criteria, array $orderBy = null)
 * @method LarProject[]    findAll()
 * @method LarProject[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LarProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LarProject::class);
    }

    /**
     * Todos los proyectos para el panel de gestión, ordenados como se muestran
     * en la web (posición manual, luego más reciente).
     *
     * @return LarProject[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Proyectos publicados de un estado dado, para la web pública.
     *
     * @param string $status Uno de LarProject::STATUS_*.
     * @return LarProject[]
     */
    public function findPublishedByStatus(string $status): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.published = true')
            ->andWhere('p.status = :status')
            ->setParameter('status', $status)
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Un proyecto publicado por su slug (detalle público). Null si no existe o
     * está en borrador.
     *
     * @param string $slug Slug del proyecto.
     * @return LarProject|null
     */
    public function findPublishedBySlug(string $slug): ?LarProject
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.published = true')
            ->andWhere('p.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
