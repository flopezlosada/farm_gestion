<?php

namespace App\Form;

use App\Entity\FinancialAccount;
use App\Repository\FinancialAccountRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Mover dinero de una cuenta propia a otra.
 *
 * No está mapeado a ninguna entidad a propósito: un traspaso no es un apunte, son DOS,
 * y quien lo teclea no piensa en dos filas sino en «saco de aquí y meto allí». El
 * formulario recoge ese hecho y TransferRecorder lo convierte en las dos mitades.
 *
 * El importe se escribe una sola vez y vale para las dos: en el libro de 2026 hay dos
 * traspasos donde la mitad que sale y la que entra llevan importes distintos (115 € de
 * diferencia), y así no se puede repetir.
 */
class AccountTransferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'required' => true,
                'constraints' => [new Assert\NotNull(message: 'Pon la fecha del traspaso.')],
            ])
            ->add('from', EntityType::class, [
                'class' => FinancialAccount::class,
                'choice_label' => 'name',
                'query_builder' => static fn (FinancialAccountRepository $r) => $r->activeQueryBuilder(),
                'placeholder' => 'Elige la cuenta de origen',
                'constraints' => [new Assert\NotNull(message: 'Di de qué cuenta sale.')],
            ])
            ->add('to', EntityType::class, [
                'class' => FinancialAccount::class,
                'choice_label' => 'name',
                'query_builder' => static fn (FinancialAccountRepository $r) => $r->activeQueryBuilder(),
                'placeholder' => 'Elige la cuenta de destino',
                'constraints' => [new Assert\NotNull(message: 'Di a qué cuenta entra.')],
            ])
            ->add('amount', NumberType::class, [
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
                'constraints' => [
                    new Assert\NotNull(message: 'Pon el importe.'),
                    new Assert\Positive(message: 'El importe se escribe en positivo.'),
                ],
            ])
            ->add('concept', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('submit', SubmitType::class, ['label' => 'Anotar el traspaso']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'constraints' => [new Assert\Callback([self::class, 'validateDistinctAccounts'])],
        ]);
    }

    /**
     * Origen y destino tienen que ser cuentas distintas. Un traspaso de una cuenta a sí
     * misma deja el saldo igual y dos apuntes de ruido en el libro.
     *
     * @param array<string, mixed>|null $data
     */
    public static function validateDistinctAccounts(?array $data, ExecutionContextInterface $context): void
    {
        if ($data === null || $data['from'] === null || $data['to'] === null) {
            return;
        }
        if ($data['from'] === $data['to']) {
            $context->buildViolation('El dinero tiene que ir a una cuenta distinta de la de origen.')
                ->atPath('to')
                ->addViolation();
        }
    }
}
