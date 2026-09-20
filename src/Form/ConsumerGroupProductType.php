<?php

namespace App\Form;

use App\Entity\ConsumerGroupCategory;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupUnit;
use App\Repository\ConsumerGroupCategoryRepository;
use App\Repository\ConsumerGroupUnitRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Una línea del catálogo de un {@see \App\Entity\Producer}: categoría, nombre,
 * unidad, descripción y si sigue activo. Entry_type de la colección dinámica
 * en {@see ProducerType}.
 *
 * SIN precio: el precio es de cada RONDA ({@see \App\Entity\ConsumerGroupRoundItem}),
 * no del catálogo — se pone al abrir/editar el pedido, nunca aquí.
 */
class ConsumerGroupProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Producto',
            ])
            ->add('category', EntityType::class, [
                'label'         => 'Categoría',
                'class'         => ConsumerGroupCategory::class,
                'choice_label'  => 'name',
                'required'      => false,
                'placeholder'   => 'Sin categoría',
                'query_builder' => static fn (ConsumerGroupCategoryRepository $r) => $r->createQueryBuilder('c')
                    ->where('c.active = true')
                    ->orderBy('c.sortOrder', 'ASC')->addOrderBy('c.name', 'ASC'),
            ])
            ->add('unit', EntityType::class, [
                'label'         => 'Unidad de venta',
                'class'         => ConsumerGroupUnit::class,
                'choice_label'  => 'name',
                'placeholder'   => 'Elige una unidad…',
                'query_builder' => static fn (ConsumerGroupUnitRepository $r) => $r->createQueryBuilder('u')
                    ->where('u.active = true')
                    ->orderBy('u.sortOrder', 'ASC')->addOrderBy('u.name', 'ASC'),
            ])
            ->add('halfUnits', CheckboxType::class, [
                'label'    => 'Se puede pedir en medias unidades',
                'help'     => 'Márcalo en lo que se vende por peso (medio kilo de queso). Déjalo sin marcar en lo que viene en formato cerrado: una garrafa o una caja no se parten.',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Descripción',
                'required' => false,
                'attr'     => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConsumerGroupProduct::class,
        ]);
    }
}
