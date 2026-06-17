<?php

namespace App\Repository;

use App\Entity\Helper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Helper|null find($id, $lockMode = null, $lockVersion = null)
 * @method Helper|null findOneBy(array $criteria, array $orderBy = null)
 * @method Helper[]    findAll()
 * @method Helper[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HelperRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Helper::class);
    }

    /**
     * Voluntarios de acogida filtrados para el listado: por texto (nombre o
     * país) y/o por procedencia. Sin filtros, devuelve todos. Trae la
     * procedencia en el mismo SELECT para no pegar a BBDD por cada fila al
     * pintar la columna.
     *
     * @param string|null $term     Texto a buscar en nombre o país (vacío = sin filtro).
     * @param int|null    $sourceId Id de procedencia (null = todas).
     * @return Helper[]
     */
    public function search(?string $term = null, ?int $sourceId = null): array
    {
        // Trae procedencia y estancias en el mismo SELECT: la lista pinta la
        // procedencia y el nº de estancias de cada fila, así que sin esto serían
        // dos queries por voluntario (N+1). Doctrine deduplica los helpers pese
        // al join 1-a-N con stay.
        $qb = $this->createQueryBuilder('h')
            ->addSelect('s', 'st')
            ->leftJoin('h.source', 's')
            ->leftJoin('h.stays', 'st')
            ->orderBy('h.name', 'ASC');

        if ($term !== null && trim($term) !== '') {
            $qb->andWhere('h.name LIKE :term OR h.country LIKE :term')
               ->setParameter('term', '%' . trim($term) . '%');
        }

        if ($sourceId !== null) {
            $qb->andWhere('s.id = :sourceId')
               ->setParameter('sourceId', $sourceId);
        }

        return $qb->getQuery()->getResult();
    }
}
