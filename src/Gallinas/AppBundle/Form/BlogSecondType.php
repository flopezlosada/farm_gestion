<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class BlogSecondType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('file',null,array(
                "required"=>null===$builder->getData()->getId()?true:false,
                "label"=>"Imagen"
            ))
            ->add('content',"textarea", array(
                'attr' => array('class' => 'tinymce','data-theme' => 'advanced'),
                'label' => "Contenido"    // simple, advanced, bbcode
            ));
           ;
    }

    /**
     * @param OptionsResolver $resolver
     */
    public function setOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Blog'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_blog';
    }
}
