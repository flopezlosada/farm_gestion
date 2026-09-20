<?php

namespace App\Form;

use App\Entity\ConsumerGroupRound;
use App\Entity\Producer;
use App\Repository\ProducerRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            ->add('ordersCloseAt', DateType::class, [
                'label'  => 'Cierre de apuntes',
                'widget' => 'single_text',
                'html5'  => true,
            ])
            ->add('deliveryDate', DateType::class, [
                'label'    => 'Fecha de entrega (se reparte con la cesta)',
                'widget'   => 'single_text',
                'html5'    => true,
                'required' => false,
                'help'     => 'Si todavía no se sabe, se puede añadir más adelante.',
            ])
            ->add('minimumType', ChoiceType::class, [
                'label'    => 'Mínimo automático',
                'required' => false,
                'placeholder' => 'Sin mínimo automático (se confirma a mano)',
                'choices'  => array_flip(ConsumerGroupRound::MINIMUM_TYPE_LABELS),
                'help'     => 'Al alcanzarse, el pedido se confirma solo y se avisa a las socias y al productor.',
            ])
            ->add('minimumValue', NumberType::class, [
                'label'    => 'Umbral del mínimo',
                'required' => false,
                'html5'    => true,
                'scale'    => 2,
                'attr'     => ['step' => '0.01', 'min' => '0', 'placeholder' => 'p. ej. 150'],
            ])
            ->add('minimumCondition', TextType::class, [
                'label'    => 'Nota del mínimo (informativa)',
                'required' => false,
                'attr'     => ['placeholder' => 'p. ej. mínimo 50 kg de aceitunas, sujeto a disponibilidad…'],
                'help'     => 'Para mínimos que no encajan arriba (una cantidad de un producto, algo aún sin concretar…). No se calcula: solo se muestra.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Descripción para las socias',
                'required' => false,
                'attr'     => ['rows' => 6],
            ])
            ->add('providerNote', TextareaType::class, [
                'label'    => 'Nota para el productor (interna)',
                'required' => false,
                'attr'     => ['rows' => 3],
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
