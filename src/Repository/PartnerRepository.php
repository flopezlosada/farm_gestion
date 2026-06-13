<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Partner|null find($id, $lockMode = null, $lockVersion = null)
 * @method Partner|null findOneBy(array $criteria, array $orderBy = null)
 * @method Partner[]    findAll()
 * @method Partner[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    // /**
    //  * @return Partner[] Returns an array of Partner objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Partner
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    $dql_no_evaluated="select t from AppBundle:AssessmentBoardLearningDifficultiesType t where t not in (:ids) and t in (:ids_assessmentBoard) ";

    */

    /**
     * Construye el QueryBuilder del listado de socixs con filtros opcionales.
     * Devuelve un QB en vez de resultados para que el controller pueda
     * delegar la paginación al PaginatorInterface de Knp.
     *
     * El left-join con partner_basket_shares se restringe a la cesta activa
     * (end_date IS NULL), de la que sólo hay una por socio principal. Se
     * añade addSelect de las asociaciones para evitar N+1 al pintar
     * modalidad y nodo/grupo en cada fila del listado.
     *
     * @param array{
     *     status?: string|null,
     *     modalities?: int[]|null,
     *     cesta?: string|null,   "yes" | "no" | null
     *     eggs?: string|null,    "yes" | "no" | null
     *     node?: int|null,
     *     wbg?: int|null,
     *     q?: string|null
     * } $filters
     */
    public function findFilteredQb(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.partner_basket_shares', 'pbs', 'WITH', 'pbs.end_date IS NULL')
            ->leftJoin('pbs.basket_share', 'bs')
            ->leftJoin('p.weekly_basket_group', 'wbg')
            ->leftJoin('wbg.node', 'n')
            ->addSelect('pbs', 'bs', 'wbg', 'n')
            ->orderBy('p.surname', 'ASC')
            ->addOrderBy('p.name', 'ASC');

        if (!empty($filters['status'])) {
            $qb->andWhere('p.status = :status')->setParameter('status', $filters['status']);
        }

        if (!empty($filters['modalities'])) {
            $qb->andWhere('bs.id IN (:modalities)')
               ->setParameter('modalities', $filters['modalities']);
        }

        // "Sin cesta": sólo aplica a socios principales (parent IS NULL); los
        // secundarios figuran sin PBS propio porque la cesta está en el parent
        // (ver memoria partner_family_share_model). "Con cesta": socios con
        // PBS activo Y sus secundarios (heredan).
        if (!empty($filters['cesta'])) {
            if ($filters['cesta'] === 'no') {
                $qb->andWhere('p.parent IS NULL')->andWhere('pbs.id IS NULL');
            } elseif ($filters['cesta'] === 'yes') {
                $qb->andWhere('pbs.id IS NOT NULL OR p.parent IS NOT NULL');
            }
        }

        // Huevos: se resuelven sobre la cesta activa (mismo join pbs que
        // modalidad). "Sí": la PBS tiene cantidad de huevos asignada. "No":
        // no la tiene — incluye tanto socios con cesta sin huevos como sin
        // PBS propio (secundarios de familia, que heredan del principal),
        // misma semántica que el filtro de modalidad.
        if (!empty($filters['eggs'])) {
            if ($filters['eggs'] === 'yes') {
                $qb->andWhere('pbs.egg_amount IS NOT NULL');
            } elseif ($filters['eggs'] === 'no') {
                $qb->andWhere('pbs.egg_amount IS NULL');
            }
        }

        if (!empty($filters['node'])) {
            $qb->andWhere('n.id = :node')->setParameter('node', (int) $filters['node']);
        }

        if (!empty($filters['wbg'])) {
            $qb->andWhere('wbg.id = :wbg')->setParameter('wbg', (int) $filters['wbg']);
        }

        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(p.name) LIKE :q OR LOWER(p.surname) LIKE :q OR LOWER(p.display_name) LIKE :q OR LOWER(p.email) LIKE :q OR p.celular LIKE :qPlain')
               ->setParameter('q', '%' . mb_strtolower($filters['q']) . '%')
               ->setParameter('qPlain', '%' . $filters['q'] . '%');
        }

        return $qb;
    }


    /**
     * Socixs en estado ACTIVO cuyo email coincide (case-insensitive) con el
     * dado. Usado por el SSO de Google para enforcar la política "exactamente
     * 1 candidato" antes de provisionar el login: 0 o >1 resultados ⇒ no se
     * loguea (ver PartnerUserProvisioner::resolveByVerifiedEmail).
     *
     * @param string $email email verificado por el proveedor de identidad
     *
     * @return Partner[] socixs activos con ese email (normalmente 0 o 1)
     */
    public function findActiveByEmail(string $email): array
    {
        return $this->createQueryBuilder('p')
            ->where('LOWER(p.email) = LOWER(:email)')
            ->andWhere('p.status = :status')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('status', Partner::STATUS_ACTIVO)
            ->getQuery()
            ->getResult();
    }

    /**
     * Candidatos elegibles para añadirse como familiar de $partner.
     *
     * Al asociar un familiar, éste pasa a ser secundario (su parent apunta al
     * principal) y la cesta vive siempre en el principal. Por eso sólo se ofrecen
     * socixs que:
     *   - están activos,
     *   - no son el propio $partner ni un familiar ya suyo,
     *   - no pertenecen aún a otra familia (parent nulo): asociarlos los robaría
     *     de su familia actual,
     *   - no tienen cesta propia activa: asociar a alguien con cesta la destruiría,
     *     así que ni se ofrece (se ataja en origen, no se avisa del borrado).
     *
     * @param Partner $partner socix principal que va a recibir el familiar
     * @return Partner[] candidatos ordenados por nombre
     */
    public function findFamiliar(Partner $partner)
    {
        $excluded = array($partner->getId());
        foreach ($partner->getRelatives() as $relative) {
            $excluded[] = $relative->getId();
        }

        return $this->createQueryBuilder('p')
            ->where('p.is_active = 1')
            ->andWhere('p.id not in (:ids)')
            ->andWhere('p.parent is null')
            ->andWhere('p.id not in (
                select identity(pbs.partner) from App\Entity\PartnerBasketShare pbs where pbs.is_active = 1
            )')
            ->orderBy('p.name', 'asc')
            ->setParameter('ids', $excluded)
            ->getQuery()
            ->getResult();
    }


    public function findActiveHasNoBasket()
    {
        $em = $this->getEntityManager();
        $dql_partner_basket = "select b from App\\Entity\\PartnerBasketShare b where b.is_active=1";
        $query_partner_basket = $em->createQuery($dql_partner_basket);
        $result = $query_partner_basket->getResult();

        $array_id_partners = array();

        if (count($result) > 0) {

            foreach ($result as $basket) {
                $array_id_partners[] = $basket->getPartner()->getId();
            }
        }

        $dql = "select p from App\\Entity\\Partner p where p.is_active=1 and p.parent is null"; //no tiene que tener parent, si no saldrían muchos que sí tienen cesta en realidad porque está asociada al parent

        if (count($array_id_partners)) {
            $dql .= ' and p.id not in (:ids) ';
        }

        $query = $em->createQuery($dql);

        if (count($array_id_partners)) {
            $query->setParameter("ids", $array_id_partners);
        }

        return $query->getResult();

    }


    /*
     * busca socios según el tipo de cesta
     */
    public function findPartnersByBasketType($type)
    {
        $em = $this->getEntityManager();
        $dql = 'select p from App\Entity\Partner p inner  join p.partner_basket_share b where b.basket_share=:type';
        $query = $em->createQuery($dql);
        $query->setParameter("type", $type);

        return $query->getResult();


    }

    public function findAmountPartnersByMonth(Basket $basket)
    {
        $em = $this->getEntityManager();
        $dql = "select count(p) from App\\Entity\\Partner p where p.inscription_date<=:basket_date and (p.demote_date is null or p.demote_date>=:basket_date)";
        $query = $em->createQuery($dql);
        $query->setParameter("basket_date", $basket->getDate()->format(('Y-m-d')));

        return $query->getSingleScalarResult();

    }


    public function findAmountBasketsByMonth(Basket $basket)
    {
        $em = $this->getEntityManager();
        $dql = "select p from App\\Entity\\Partner p where p.inscription_date<=:basket_date and (p.demote_date is null or p.demote_date>=:basket_date)";
        $query = $em->createQuery($dql);
        $query->setParameter("basket_date", $basket->getDate()->format(('Y-m-d')));
        $partners = $query->getResult();
        $amount = 0;
        foreach ($partners as $partner) {
            if ($partner->hasActiveBasket()) {
                $amount += $partner->getActiveBasket()->getBasketShare()->getCompleteBasketEquivalence();
            }
        }

        return $amount;
    }

    /**
     * Para los últimos N meses (incluyendo el actual), devuelve por mes el
     * número de altas (inscription_date) y de bajas (demote_date). Sirve
     * de base para la pantalla de evolución; cuando importemos los
     * PartnerEvent retroactivos podremos afinar y diferenciar pausas.
     *
     * @return array<int, array{month:string, label:string, joins:int, leaves:int, net:int}>
     */
    public function countMonthlyJoinsAndLeaves(int $months = 13): array
    {
        $em = $this->getEntityManager();

        $joinsRaw = $em->createQuery(
            "SELECT SUBSTRING(p.inscription_date, 1, 7) AS month, COUNT(p.id) AS total
             FROM App\\Entity\\Partner p
             WHERE p.inscription_date IS NOT NULL
             GROUP BY month"
        )->getArrayResult();

        $leavesRaw = $em->createQuery(
            "SELECT SUBSTRING(p.demote_date, 1, 7) AS month, COUNT(p.id) AS total
             FROM App\\Entity\\Partner p
             WHERE p.demote_date IS NOT NULL
             GROUP BY month"
        )->getArrayResult();

        $joinsByMonth = [];
        foreach ($joinsRaw as $row) {
            $joinsByMonth[$row['month']] = (int) $row['total'];
        }
        $leavesByMonth = [];
        foreach ($leavesRaw as $row) {
            $leavesByMonth[$row['month']] = (int) $row['total'];
        }

        $monthsEs = [
            1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
            7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic',
        ];

        $series = [];
        $startOfCurrent = new \DateTimeImmutable('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthDate = $startOfCurrent->modify("-{$i} months");
            $key = $monthDate->format('Y-m');
            $joins = $joinsByMonth[$key] ?? 0;
            $leaves = $leavesByMonth[$key] ?? 0;
            $series[] = [
                'month' => $key,
                'label' => $monthsEs[(int) $monthDate->format('n')] . ' ' . $monthDate->format('y'),
                'joins' => $joins,
                'leaves' => $leaves,
                'net' => $joins - $leaves,
            ];
        }

        return $series;
    }

    /**
     * Nº total de socios en estado ACTIVO, en una sola query agregada.
     *
     * No se puede derivar sumando {@see self::countActiveByGroup()}: ese método
     * excluye a los activos sin grupo de recogida, así que la suma se quedaría
     * corta. Por eso este contador independiente.
     *
     * @return int socios con status = ACTIVO
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :status')
            ->setParameter('status', Partner::STATUS_ACTIVO)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nº de socios ACTIVOS que aún no tienen acceso a la app: ningún {@see User}
     * los referencia. El vínculo vive en el lado User ({@see User::$partner},
     * OneToOne), de ahí la subconsulta NOT IN sobre los partner_id ya ocupados
     * (Partner no tiene relación inversa que permita un LEFT JOIN directo).
     *
     * Alimenta el aviso "pendientes de dar acceso" del dashboard de admin: cuánta
     * gente falta por enganchar al login (magic-link).
     *
     * @return int socios ACTIVO sin User vinculado
     */
    public function countActiveWithoutAccess(): int
    {
        $linkedPartnerIds = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(u.partner)')
            ->from(User::class, 'u')
            ->where('u.partner IS NOT NULL')
            ->getDQL();

        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :status')
            ->andWhere("p.id NOT IN ({$linkedPartnerIds})")
            ->setParameter('status', Partner::STATUS_ACTIVO)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nº de socios en estado ACTIVO por grupo de recogida, en una sola query
     * agregada. Indexado por id de WeeklyBasketGroup. Los grupos sin socios
     * activos no aparecen (el llamante asume 0).
     *
     * @return array<int, int> groupId => nº de socios activos
     */
    public function countActiveByGroup(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.weekly_basket_group) AS gid', 'COUNT(p.id) AS total')
            ->where('p.status = :status')
            ->andWhere('p.weekly_basket_group IS NOT NULL')
            ->setParameter('status', Partner::STATUS_ACTIVO)
            ->groupBy('p.weekly_basket_group')
            ->getQuery()
            ->getArrayResult();

        $byGroup = [];
        foreach ($rows as $row) {
            $byGroup[(int) $row['gid']] = (int) $row['total'];
        }

        return $byGroup;
    }

    /**
     * Socios en estado ACTIVO sin grupo de recogida REAL: o con grupo nulo, o
     * aparcados en el grupo-centinela "Sin grupo" del catálogo. Son los únicos
     * candidatos al "añadir" rápido de la ficha de grupo; mover un socio que ya
     * tiene grupo real a otro se hace desde la ficha del socio (admin) o su
     * panel (autoservicio).
     *
     * @return Partner[]
     */
    public function findActiveWithoutGroup(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.weekly_basket_group', 'g')
            ->where('p.status = :status')
            ->andWhere('p.weekly_basket_group IS NULL OR g.name = :sentinel')
            ->setParameter('status', Partner::STATUS_ACTIVO)
            ->setParameter('sentinel', 'Sin grupo')
            ->orderBy('p.surname', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
