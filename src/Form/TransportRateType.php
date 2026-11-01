<?php

namespace App\Form;

use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un precio de transporte nuevo para un grupo de recogida desde una fecha. No edita
 * ninguno: el de antes queda como lo que valía hasta ese día.
 */
class TransportRateType extends AbstractType
{
    /**
     * Grupo (agrupados por nodo), precio por cesta y reparto, y desde cuándo.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pickupGroup', EntityType::class, [
                'class' => WeeklyBasketGroup::class,
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('g')
                    ->addSelect('n')->leftJoin('g.node', 'n')
                    ->orderBy('n.name', 'ASC')->addOrderBy('g.name', 'ASC'),
                'choice_label' => 'name',
                'group_by' => static fn (WeeklyBasketGroup $g): string => $g->getNode()?->getName() ?? 'Sin nodo',
                'placeholder' => 'Elige el grupo de recogida',
                'constraints' => [new Assert\NotNull(message: 'Elige a qué grupo se lleva.')],
            ])
            ->add('pricePerDelivery', AmountType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe el precio.'),
                    new Assert\Positive(message: 'El precio tiene que ser mayor que cero.'),
                ],
            ])
            ->add('validFrom', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Escribe desde cuándo vale.')],
            ]);
    }
}
