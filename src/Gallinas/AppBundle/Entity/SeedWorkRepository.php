<?php

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * SeedWorkRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class SeedWorkRepository extends EntityRepository
{
    public function findFirstSeedWork(CropWorking $crop_working)
    {
        $em = $this->getEntityManager();
        $dql = "select sw from AppBundle:SeedWork sw where sw.crop_working=:crop_working ORDER by sw.real_date asc";
        $query = $em->createQuery($dql);
        $query->setParameter("crop_working", $crop_working);
        $query->setMaxResults(1);

        if ($query->getResult())
        {
            return $query->getResult()[0];
        }

        return null;

    }
}
