<?php
 
namespace Gallinas\AppBundle\Form\DataTransformer;
use Doctrine\Common\Persistence\ObjectManager;
 
class UserToIntTransformer extends EntityToIntTransformer
{
    /**
     * @param ObjectManager $om
     */
    public function __construct(ObjectManager $om)
    {
        parent::__construct($om);
        $this->setEntityClass("Gallinas\AppBundle\Entity\User");
        $this->setEntityRepository("AppBundle:User");
        $this->setEntityType("User");
    }
 
}