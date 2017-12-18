<?php

namespace Gallinas\AppBundle\Form;

use FOS\UserBundle\Form\Type\ProfileFormType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class UserType extends ProfileFormType
{

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_user';
    }
}
