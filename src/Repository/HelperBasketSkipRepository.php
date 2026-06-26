<?php

namespace App\Repository;

use App\Entity\Helper;
use App\Entity\HelperBasketSkip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method HelperBasketSkip|null find($id, $lockMode = null, $lockVersion = null)
 * @method HelperBasketSkip|null findOneBy(array $criteria, array $orderBy = null)
 * @method HelperBasketSkip[]    findAll()
 * @method HelperBasketSkip[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HelperBasketSkipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HelperBasketSkip::class);
    }

    /**
     * El skip de un voluntario en una fecha concreta, o null si recoge ese día.
     * Lo usa el toggle del calendario para decidir si crear o borrar el skip.
     *
     * @param Helper             $helper
     * @param \DateTimeImmutable $date
     * @return HelperBasketSkip|null
     */
    public function findOneByHelperAndDate(Helper $helper, \DateTimeImmutable $date): ?HelperBasketSkip
    {
        return $this->findOneBy(['helper' => $helper, 'date' => $date]);
    }

    /**
     * Fechas (formato 'Y-m-d') en las que un voluntario salta la recogida. Para
     * marcar las semanas saltadas en su calendario de recogida sin N+1. Devuelve
     * un set (clave = fecha) para comprobar pertenencia en O(1).
     *
     * @param Helper $helper
     * @return array<string,true>
     */
    public function skippedDatesForHelper(Helper $helper): array
    {
        $set = [];
        foreach ($this->findBy(['helper' => $helper]) as $skip) {
            $set[$skip->getDate()->format('Y-m-d')] = true;
        }

        return $set;
    }

    /**
     * IDs de los voluntarios (de entre los dados) que SALTAN la recogida en la
     * fecha indicada. El resolver del listado los excluye. Se filtra por la
     * lista de helpers para no traer toda la tabla cuando sólo importan los que
     * reparten ese día.
     *
     * @param int[]              $helperIds IDs de los voluntarios candidatos.
     * @param \DateTimeImmutable $date      Fecha física de entrega.
     * @return int[] IDs de los voluntarios con skip en esa fecha.
     */
    public function helperIdsSkippingDate(array $helperIds, \DateTimeImmutable $date): array
    {
        if ($helperIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.helper) AS helperId')
            ->andWhere('s.helper IN (:ids)')
            ->andWhere('s.date = :date')
            ->setParameter('ids', $helperIds)
            ->setParameter('date', $date)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'helperId'));
    }
}
