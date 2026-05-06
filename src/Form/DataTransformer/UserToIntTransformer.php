<?php
 
namespace App\Form\DataTransformer;
use Doctrine\Persistence\ObjectManager;
 
class UserToIntTransformer extends EntityToIntTransformer
{
    /**
     * @param ObjectManager $om
     */
    public function __construct(ObjectManager $om)
    {
        parent::__construct($om);
        $this->setEntityClass("App\Entity\User");
        $this->setEntityRepository(\App\Entity\User::class);
        $this->setEntityType("User");
    }
 
}