<?php

namespace App\Form;

use App\Entity\Partner;
use App\Entity\RemittanceLine;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un recibo de una remesa en borrador: las columnas del Excel del banco, más el socix
 * al que corresponde para poder contrastarlo con sus cestas.
 */
class RemittanceLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('partner', EntityType::class, [
                'label' => 'Socix',
                'class' => Partner::class,
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('p')->orderBy('p.name')->addOrderBy('p.surname'),
                'choice_label' => static fn (Partner $p): string => $p->getLegalName(),
                'required' => false,
                'placeholder' => '— sin socix —',
            ])
            ->add('debtorName', TextType::class, [
                'label' => 'Nombre de quien paga',
                'attr' => ['maxlength' => RemittanceLine::MAX_NAME],
            ])
            ->add('iban', TextType::class, [
                'label' => 'Cuenta (IBAN)',
                'attr' => ['maxlength' => 42, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('mandateReference', TextType::class, [
                'label' => 'Referencia del mandato',
                'attr' => ['maxlength' => RemittanceLine::MAX_REFERENCE],
            ])
            ->add('mandateSignedOn', DateType::class, [
                'label' => 'Firma del mandato',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('debitReference', TextType::class, [
                'label' => 'Referencia del adeudo',
                'attr' => ['maxlength' => RemittanceLine::MAX_REFERENCE],
            ])
            ->add('sequenceType', ChoiceType::class, [
                'label' => 'Tipo de adeudo',
                'choices' => array_flip(RemittanceLine::SEQUENCE_LABELS),
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Importe',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RemittanceLine::class]);
    }
}
