<?php

namespace App\Form;

use App\Entity\ConsumerGroupRound;
use App\Entity\Producer;
use App\Repository\ProducerRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form de la CABECERA de una ronda: productor (del catálogo) y datos de la ronda.
 * Los productos de la ronda ({@see \App\Entity\ConsumerGroupRoundItem}) NO se editan
 * aquí, sino en la pantalla de "productos de la ronda" (se siembran del catálogo del
 * productor al crearla).
 *
 * Opción `lock_producer` (true en edición): el productor no se cambia una vez creada
 * la ronda, porque sus productos cuelgan de ese catálogo.
 */
class ConsumerGroupRoundType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Título',
                'attr'  => ['placeholder' => 'Fruta de temporada — julio'],
            ])
            ->add('producer', EntityType::class, [
                'label'         => 'Productor',
                'class'         => Producer::class,
                'choice_label'  => 'name',
                'placeholder'   => 'Elige un productor…',
                'disabled'      => $options['lock_producer'],
                'query_builder' => static fn (ProducerRepository $r) => $r->createQueryBuilder('p')
                    ->where('p.active = true')
                    ->orderBy('p.name', 'ASC'),
            ])
            ->add('ordersCloseAt', DateTimeType::class, [
                'label'  => 'Cierre de apuntes',
                'widget' => 'single_text',
                'html5'  => true,
            ])
            ->add('deliveryDate', DateType::class, [
                'label'  => 'Fecha de entrega (se reparte con la cesta)',
                'widget' => 'single_text',
                'html5'  => true,
            ])
            ->add('minimumCondition', TextType::class, [
                'label'    => 'Condición de mínimo (informativa)',
                'required' => false,
                'attr'     => ['placeholder' => 'p. ej. mínimo 150 € / 50 kg / 10 pedidos'],
                'help'     => 'No se calcula sola: la comisión confirma la ronda a mano viendo los apuntes.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Descripción para las socias',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('providerNote', TextareaType::class, [
                'label'    => 'Nota para el productor (interna)',
                'required' => false,
                'attr'     => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => ConsumerGroupRound::class,
            'lock_producer'  => false,
        ]);
        $resolver->setAllowedTypes('lock_producer', 'bool');
    }
}
