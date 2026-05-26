<?php

namespace App\Repository;

use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Node|null find($id, $lockMode = null, $lockVersion = null)
 * @method Node|null findOneBy(array $criteria, array $orderBy = null)
 * @method Node[]    findAll()
 * @method Node[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Node::class);
    }

    /**
     * Devuelve un nodo por nombre exacto, o null si no existe.
     *
     * @param string $name
     * @return Node|null
     */
    public function findByName(string $name): ?Node
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * @return Node[]
     */
    public function findByCadence(string $cadence): array
    {
        return $this->findBy(['cadence' => $cadence], ['name' => 'ASC']);
    }
}
