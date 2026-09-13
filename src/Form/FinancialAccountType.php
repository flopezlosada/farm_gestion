<?php

namespace App\Form;

use App\Entity\FinancialAccount;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una cuenta donde vive el dinero: un banco o una caja de efectivo.
 *
 * El saldo de apertura es el punto de partida de todo lo que la aplicación calcula. En
 * cuanto la cuenta tiene apuntes, cambiarlo movería todos los saldos históricos de golpe
 * y en silencio, así que el formulario lo BLOQUEA —opción `lock_opening`— en vez de
 * limitarse a advertirlo. Mientras la cuenta está vacía sí se corrige, que es cuando
 * tiene sentido: acabas de teclearlo mal.
 */
class FinancialAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locked = (bool) $options['lock_opening'];

        $builder
            ->add('name', TextType::class, [
                'required' => true,
            ])
            ->add('kind', ChoiceType::class, [
                'choices' => array_flip(FinancialAccount::KIND_LABELS),
                'placeholder' => false,
            ])
            ->add('iban', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(max: 34)],
            ])
            ->add('openingBalance', NumberType::class, [
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
                'disabled' => $locked,
                'constraints' => [new Assert\NotNull(message: 'Pon el saldo con el que arranca.')],
            ])
            ->add('openingDate', DateType::class, [
                'widget' => 'single_text',
                'disabled' => $locked,
                'constraints' => [new Assert\NotNull(message: 'Pon la fecha de ese saldo.')],
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
        $resolver->setDefaults([
            'data_class' => FinancialAccount::class,
            // La cuenta ya tiene apuntes: el saldo de apertura deja de ser tecleable.
            'lock_opening' => false,
        ]);
        $resolver->setAllowedTypes('lock_opening', 'bool');
    }
}
