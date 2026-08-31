<?php

namespace App\Form;

use App\Entity\ConsumerGroupCategory;
use App\Entity\ConsumerGroupProduct;
use App\Repository\ConsumerGroupCategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Una línea del catálogo de un {@see \App\Entity\Producer}: categoría, nombre,
 * unidad, descripción, precio de referencia y si sigue activo. Entry_type de la
 * colección dinámica en {@see ProducerType}.
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
            ->add('unit', TextType::class, [
                'label' => 'Unidad',
                'attr'  => ['placeholder' => 'kg, L, docena, ud…'],
            ])
            ->add('referencePrice', MoneyType::class, [
                'label'    => 'Precio de referencia',
                'currency' => 'EUR',
                'scale'    => 2,
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Descripción',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('active', CheckboxType::class, [
                'label'    => 'Activo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConsumerGroupProduct::class,
        ]);
    }
}
