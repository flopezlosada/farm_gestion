<?php

namespace App\Form;

use App\Entity\VolunteerCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de una categoría de voluntariado.
 *
 * La descripción se pinta en la ficha del socix junto a la casilla, así que
 * cuenta: quien marca "obras" tiene que saber a qué se está apuntando antes de
 * marcarla, o el aviso dirigido acaba llegando a gente que no puede ir.
 */
class VolunteerCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'p. ej. Huerta, Reparto, Obras, Oficina…'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Qué entra aquí',
                'required' => false,
                'help' => 'Se le enseña a cada socix junto a la casilla, para que sepa qué está marcando.',
                'attr' => ['rows' => 3],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Se sigue ofreciendo',
                'required' => false,
                'help' => 'Desmárcala para retirarla sin perder el histórico de tareas que la usaron.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerCategory::class,
        ]);
    }
}
