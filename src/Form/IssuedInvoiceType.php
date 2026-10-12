<?php

namespace App\Form;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un borrador de factura emitida: cliente, serie, fecha, qué se hizo y sus líneas.
 *
 * La serie sólo se elige en una factura normal: una rectificativa nace en la suya
 * ({@see IssuedInvoice::rectificationOf()}) y no se cambia.
 */
class IssuedInvoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customer', EntityType::class, [
                'class' => Customer::class,
                'choice_label' => static fn (Customer $c): string => $c->getName() . ($c->getTaxId() ? ' · ' . $c->getTaxId() : ''),
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                'placeholder' => '— elige el cliente —',
                'required' => false,
            ])
            ->add('issueDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('description', TextareaType::class, ['required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]])
            ->add('exemptionNote', TextareaType::class, ['required' => false, 'attr' => ['rows' => 2, 'maxlength' => 1000]])
            ->add('lines', CollectionType::class, [
                'entry_type' => IssuedInvoiceLineType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'error_bubbling' => false,
            ]);

        // La serie, sólo si la factura no es una rectificativa.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $invoice = $event->getData();
            if ($invoice instanceof IssuedInvoice && $invoice->isRectifying()) {
                return;
            }
            $event->getForm()->add('series', ChoiceType::class, [
                'choices' => array_combine(
                    array_map(static fn (string $s): string => IssuedInvoice::SERIES_LABELS[$s], IssuedInvoice::CHOOSABLE_SERIES),
                    IssuedInvoice::CHOOSABLE_SERIES,
                ),
                'expanded' => true,
            ]);
        });

        // Las posiciones siguen el orden en que quedaron las líneas en el formulario.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $invoice = $event->getData();
            if ($invoice instanceof IssuedInvoice) {
                $invoice->renumberLines();
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IssuedInvoice::class]);
    }
}
