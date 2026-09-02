<?php

namespace App\Form;

use App\Entity\VolunteerPlace;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de un sitio donde se hace voluntariado.
 */
class VolunteerPlaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'p. ej. La huerta'],
            ])
            ->add('directions', TextareaType::class, [
                'label' => 'Cómo llegar',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Lo que hay que saber del sitio: dónde se aparca, quién tiene la llave. Se enseña con cada tarea que ocurre aquí, así que no hay que repetirlo en su descripción.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Se sigue usando',
                'required' => false,
                'help' => 'Desmárcalo para retirarlo: deja de ofrecerse al crear tareas, y las que ya lo tienen siguen diciendo dónde fueron.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => VolunteerPlace::class]);
    }
}
