<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskNoteType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('content',"textarea", array(
                'attr' => array('class' => 'tinymce','data-theme' => 'advanced'),
                'label' => "Contenido"    // simple, advanced, bbcode
            ));
    }

    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\TaskNote'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_tasknote';
    }
}
