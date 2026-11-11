<?php

namespace App\Repository;

use App\Entity\BankPayerAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankPayerAlias>
 */
class BankPayerAliasRepository extends ServiceEntityRepository
{
    /** @param ManagerRegistry $registry el registro de Doctrine */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankPayerAlias::class);
    }

    /**
     * Los ordenantes aprendidos de entre unos, por ordenante normalizado. Una consulta.
     *
     * @param list<string> $keys ordenantes normalizados
     *
     * @return array<string, BankPayerAlias>
     */
    public function byKey(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $found = [];
        foreach ($this->createQueryBuilder('a')->addSelect('p')->innerJoin('a.partner', 'p')
            ->andWhere('a.payerKey IN (:keys)')->setParameter('keys', array_values(array_unique($keys)))
            ->getQuery()->getResult() as $alias) {
            $found[$alias->getPayerKey()] = $alias;
        }

        return $found;
    }
}
