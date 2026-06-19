<?php

namespace App\Form;

use App\Entity\Helper;
use App\Entity\HelperSource;
use App\Entity\Node;
use App\Repository\HelperSourceRepository;
use App\Repository\NodeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            ->add('basketActive', CheckboxType::class, [
                'label' => 'Se lleva cesta de reparto',
                'required' => false,
                'help' => 'Aparecerá en el listado de reparto durante su estancia.',
            ])
            ->add('basketNode', EntityType::class, [
                'label' => 'Nodo de recogida',
                'class' => Node::class,
                'required' => false,
                'placeholder' => 'Torremocha (por defecto)',
                'query_builder' => fn (NodeRepository $r) => $r->createQueryBuilder('n')->orderBy('n.name', 'ASC'),
                'help' => 'Dónde recoge la cesta. En blanco = Torremocha.',
            ])
            ->add('basketVegBaskets', IntegerType::class, [
                'label' => 'Cestas de verdura',
                'attr' => ['min' => 0],
                'help' => 'Número de cestas por semana (0 = solo huevos).',
            ])
            ->add('basketEggDozens', NumberType::class, [
                'label' => 'Docenas de huevos',
                'scale' => 1,
                'attr' => ['min' => 0, 'step' => 0.5],
                'help' => 'Docenas por semana (admite 0,5).',
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
