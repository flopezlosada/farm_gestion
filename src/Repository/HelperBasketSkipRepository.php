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
     * El skip de CESTA ENTERA de un voluntario en una fecha concreta, o null si
     * recoge ese día. Lo usa el toggle del calendario para decidir si crear o
     * borrar el skip; los skips por componente (huevos) no son suyos y no debe
     * verlos ni borrarlos al alternar.
     *
     * @param Helper             $helper
     * @param \DateTimeImmutable $date
     * @return HelperBasketSkip|null
     */
    public function findOneByHelperAndDate(Helper $helper, \DateTimeImmutable $date): ?HelperBasketSkip
    {
        return $this->findOneBy(['helper' => $helper, 'date' => $date, 'component' => null]);
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
        foreach ($this->findBy(['helper' => $helper, 'component' => null]) as $skip) {
            $set[$skip->getDate()->format('Y-m-d')] = true;
        }

        return $set;
    }

    /**
     * IDs de los voluntarios (de entre los dados) que SALTAN la recogida ENTERA
     * en la fecha indicada. El resolver del listado los excluye. Se filtra por
     * la lista de helpers para no traer toda la tabla cuando sólo importan los
     * que reparten ese día.
     *
     * @param int[]              $helperIds IDs de los voluntarios candidatos.
     * @param \DateTimeImmutable $date      Fecha física de entrega.
     * @return int[] IDs de los voluntarios con skip de cesta entera esa fecha.
     */
    public function helperIdsSkippingDate(array $helperIds, \DateTimeImmutable $date): array
    {
        return $this->helperIdsSkipping($helperIds, $date, null);
    }

    /**
     * IDs de los voluntarios (de entre los dados) a los que se les ha retirado
     * un COMPONENTE concreto en la fecha indicada. Siguen recogiendo su cesta,
     * pero sin ese componente.
     *
     * @param int[]              $helperIds   IDs de los voluntarios candidatos.
     * @param \DateTimeImmutable $date        Fecha física de entrega.
     * @param int                $componentId BasketComponent::ID_VEGETABLES | ID_EGGS.
     * @return int[]
     */
    public function helperIdsSkippingComponent(array $helperIds, \DateTimeImmutable $date, int $componentId): array
    {
        return $this->helperIdsSkipping($helperIds, $date, $componentId);
    }

    /**
     * Motor común de las dos consultas de arriba: los NULL no se comparan con
     * `=` en SQL, así que la rama de cesta entera necesita `IS NULL` explícito.
     *
     * @param int[]              $helperIds
     * @param \DateTimeImmutable $date
     * @param int|null           $componentId Null = skips de cesta entera.
     * @return int[]
     */
    private function helperIdsSkipping(array $helperIds, \DateTimeImmutable $date, ?int $componentId): array
    {
        if ($helperIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.helper) AS helperId')
            ->andWhere('s.helper IN (:ids)')
            ->andWhere('s.date = :date')
            ->setParameter('ids', $helperIds)
            ->setParameter('date', $date);

        if ($componentId === null) {
            $qb->andWhere('s.component IS NULL');
        } else {
            $qb->andWhere('s.component = :component')->setParameter('component', $componentId);
        }

        return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'helperId'));
    }
}
