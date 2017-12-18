<?php

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * RecipientRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class RecipientRepository extends EntityRepository
{
    public function findByEggWarning()
    {
        $em = $this->getEntityManager();
        $product = $em->getRepository("AppBundle:Product")->find(1);
        $dql = "
        select p, s, DATEDIFF(CURRENT_DATE(),s.gift_date)- (p.often_buying_eggs) as diff
        from AppBundle:Recipient p
        inner join p.gifts s
        with (p.often_buying_eggs-3) < DATEDIFF(CURRENT_DATE(),s.gift_date)
        and s.product=:product
        and p.regular=1
        and s.gift_date=(select max(ss.gift_date) from AppBundle:Gift ss where ss.recipient=p and ss.product=:product)
        order by diff desc
        ";

        $query = $em->createQuery($dql);
        $query->setParameter("product", $product);

        return $query->getResult();
    }
}
