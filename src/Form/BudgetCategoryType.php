<?php

namespace App\Form;

use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Repository\BudgetCategoryGroupRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Una partida del libro: a qué se imputa el dinero.
 *
 * El grupo no es decoración: es la unidad con la que se compara el real contra el
 * presupuesto, y por eso el grupo cuadra aunque la granularidad de las partidas cambie
 * de un año a otro. Cambiarle el grupo a una partida mueve su historia entera de sitio.
 *
 * El grupo de TRASPASOS no se ofrece: su única partida la crea el catálogo y la usa
 * {@see \App\Service\Accounting\TransferRecorder}. Una segunda partida de traspasos
 * haría ambiguo cuál usar.
 */
class BudgetCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('group', EntityType::class, [
                'class' => BudgetCategoryGroup::class,
                'choice_label' => 'name',
                'query_builder' => static fn (BudgetCategoryGroupRepository $r) => $r->createQueryBuilder('g')
                    ->andWhere('g.kind != :transfer')
                    ->setParameter('transfer', BudgetCategoryGroup::KIND_TRANSFER)
                    ->orderBy('g.sortOrder', 'ASC'),
                'placeholder' => 'Elige el grupo',
            ])
            ->add('name', TextType::class, [
                'required' => true,
            ])
            ->add('description', TextType::class, [
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'required' => false,
                'empty_data' => '0',
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
            ])
            ->add('submit', SubmitType::class, ['label' => 'Guardar']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BudgetCategory::class]);
    }
}
