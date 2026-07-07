<?php

namespace App\Form;

use App\Entity\Producer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form de alta/edición de un {@see Producer} con su catálogo de productos
 * ({@see ConsumerGroupProductType}) en una sola pantalla. El catálogo es
 * persistente y reutilizable entre rondas.
 */
class ProducerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre del productor',
            ])
            ->add('contactName', TextType::class, [
                'label'    => 'Persona de contacto',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label'    => 'Email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label'    => 'Teléfono',
                'required' => false,
            ])
            ->add('web', TextType::class, [
                'label'    => 'Web',
                'required' => false,
            ])
            ->add('minimumNote', TextType::class, [
                'label'    => 'Pedido mínimo (por defecto)',
                'required' => false,
                'attr'     => ['placeholder' => 'p. ej. mínimo 150 € / 50 kg'],
                'help'     => 'Precarga la condición de mínimo al abrir una ronda de este productor. Informativa: no se calcula sola.',
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'Notas internas',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('selfManaged', CheckboxType::class, [
                'label'    => 'Autogestiona sus pedidos',
                'required' => false,
                'help'     => 'Si se marca, el productor lleva sus propias rondas (tendrá acceso propio). Si no, las gestiona la comisión.',
            ])
            ->add('active', CheckboxType::class, [
                'label'    => 'Activo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Producer::class,
        ]);
    }
}
