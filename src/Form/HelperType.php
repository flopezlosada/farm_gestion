<?php

namespace App\Form;

use App\Entity\Helper;
use App\Entity\HelperSource;
use App\Repository\HelperSourceRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar un {@see Helper} (voluntario de acogida del albergue).
 * Los campos studies/university sólo los rellenan los de prácticas: van como
 * opcionales en una sección aparte, no se imponen al resto.
 */
class HelperType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre completo',
            ])
            ->add('source', EntityType::class, [
                'label' => 'Procedencia',
                'class' => HelperSource::class,
                'query_builder' => fn (HelperSourceRepository $r) => $r->createQueryBuilder('s')->orderBy('s.name', 'ASC'),
                'placeholder' => 'Elige una procedencia',
            ])
            ->add('country', TextType::class, [
                'label' => 'País',
                'required' => false,
            ])
            ->add('email', TextType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'Teléfono',
                'required' => false,
            ])
            ->add('languages', TextType::class, [
                'label' => 'Idiomas',
                'required' => false,
                'help' => 'Texto libre, p. ej. «inglés, francés».',
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Fecha de nacimiento',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('emergencyContact', TextType::class, [
                'label' => 'Contacto de emergencia',
                'required' => false,
                'help' => 'Nombre y teléfono de quien avisar si pasa algo.',
            ])
            ->add('studies', TextType::class, [
                'label' => 'Área de estudios',
                'required' => false,
                'help' => 'Sólo para estudiantes en prácticas.',
            ])
            ->add('university', TextType::class, [
                'label' => 'Universidad',
                'required' => false,
                'help' => 'Sólo para estudiantes en prácticas.',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Helper::class,
        ]);
    }
}
