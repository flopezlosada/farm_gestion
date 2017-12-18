<?php

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * ProductionRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ProductionRepository extends EntityRepository
{
    public function findRealProduction($crop, $year)
    {
        $em = $this->getEntityManager();
        $dql = "select p from AppBundle:Production p where YEAR(p.production_date)=:year and p.crop=:crop order by p.production_date asc";
        $query = $em->createQuery($dql);
        $query->setParameter("year", $year);
        $query->setParameter("crop", $crop);

        return $query->getResult();
    }

    /**
     * @param $crop
     * @param $year
     * @return mixed|string
     * esta función es la antigua cuando usaba el crop para indicar las producciones por temporada. Ahora ya no se usa
     */

    public function findTotalProduction($crop, $year)
    {
        $em = $this->getEntityManager();
        $dql = "select sum(p.amount) as total from AppBundle:Production p where YEAR(p.production_date)=:year and p.crop=:crop";
        $query = $em->createQuery($dql);
        $query->setParameter("year", $year);
        $query->setParameter("crop", $crop);

        if ($query->getSingleScalarResult())
        {
            return $query->getSingleScalarResult();
        } else
        {
            return "0";
        }
    }

    /**
     * @param $crop_working
     * @return mixed|string
     * Esta función es la nueva que uso con el cambio de crop a cropworking. No hace falta el año para esta, ya que es
     * el cropworking quien define la temporada
     */
    public function findTotalCropProduction($crop_working)
    {
        $em = $this->getEntityManager();
        $dql = "select sum(p.amount) as total from AppBundle:Production p where p.crop_working=:crop_working";
        $query = $em->createQuery($dql);
        $query->setParameter("crop_working", $crop_working);

        if ($query->getSingleScalarResult())
        {
            return $query->getSingleScalarResult();
        } else
        {
            return "0";
        }
    }

    public function findWeeks($year)
    {
        $em = $this->getEntityManager();
        $dql = "select p.week from AppBundle:Production p where YEAR(p.production_date)=:year GROUP BY p.week ORDER by p.week DESC ";
        $query = $em->createQuery($dql);
        $query->setParameter("year", $year);

        return $query->getResult();
    }

    public function findProductionInWeek($week,$year)
    {

        $em = $this->getEntityManager();
        $dql = "select p from AppBundle:Production p where YEAR(p.production_date)=:year  and p.week=:week";
        $query = $em->createQuery($dql);
        $query->setParameter("year", $year);
        $query->setParameter("week", $week);

        return $query->getResult();
    }
}
