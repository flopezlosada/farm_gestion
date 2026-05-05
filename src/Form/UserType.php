<?php

namespace App\Form;

use FOS\UserBundle\Form\Type\ProfileFormType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends ProfileFormType
{

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_user';
    }
}
