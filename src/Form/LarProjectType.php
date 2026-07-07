<?php

namespace App\Form;

use App\Entity\LarProject;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar un {@see LarProject}. Las fotos y vídeos se gestionan
 * aparte (galería polimórfica), en una pantalla propia tras guardar la ficha.
 */
class LarProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Título',
                'help' => 'El nombre del proyecto tal cual aparecerá en la web.',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => array_flip(LarProject::TYPES),
                'help' => 'Se muestra como etiqueta en la tarjeta.',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Resumen',
                'attr' => ['rows' => 3],
                'help' => 'Texto corto para la tarjeta del listado (máx. 500 caracteres).',
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => ['class' => 'tinymce', 'data-theme' => 'advanced'],
                'help' => 'Cuerpo completo de la página del proyecto. Admite texto con formato.',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Estado',
                'choices' => [
                    'Activo (en marcha)' => LarProject::STATUS_ACTIVE,
                    'Finalizado (memoria)' => LarProject::STATUS_FINISHED,
                ],
                'help' => 'Los activos se muestran destacados; los finalizados quedan como trayectoria.',
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Orden',
                'attr' => ['min' => 0],
                'help' => 'Menor número = aparece antes. Empates se ordenan por fecha.',
            ])
            ->add('published', CheckboxType::class, [
                'label' => 'Publicado',
                'required' => false,
                'help' => 'Si se desmarca, el proyecto queda en borrador y no se ve en la web.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LarProject::class,
        ]);
    }
}
