<?php

namespace App\Form;

use App\Entity\DonationInitiative;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un ajuste de la cuota de un socix: si paga de más o de menos, cuánto, por qué,
 * desde qué mes y hasta cuál. Se pide la dirección aparte y el importe en positivo:
 * escribir «-15» para una reducción es fácil de equivocar.
 *
 * Una aportación (paga de más) puede ir a una iniciativa abierta; una reducción no.
 */
class FeeAdjustmentType extends AbstractType
{
    public const MORE = 'more';
    public const LESS = 'less';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $month = [
            'widget' => 'single_text',
            'html5' => false,
            'format' => 'yyyy-MM',
            'input' => 'datetime_immutable',
            'attr' => ['type' => 'month', 'placeholder' => 'aaaa-mm'],
        ];

        $builder
            ->add('direction', ChoiceType::class, [
                'choices' => ['Paga de más' => self::MORE, 'Paga de menos' => self::LESS],
                'expanded' => true,
                'constraints' => [new Assert\NotNull(message: 'Elige si paga de más o de menos.')],
            ])
            ->add('amount', NumberType::class, [
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01', 'inputmode' => 'decimal'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe cuánto.'),
                    new Assert\Positive(message: 'Escribe la cantidad sin signo: lo de más o de menos va arriba.'),
                ],
            ])
            ->add('reason', TextType::class, [
                'attr' => ['maxlength' => 255],
                'constraints' => [new Assert\NotBlank(message: 'Escribe el motivo.'), new Assert\Length(max: 255)],
            ])
            ->add('initiative', EntityType::class, [
                'class' => DonationInitiative::class,
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('i')->andWhere('i.open = true')->orderBy('i.name'),
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— para la asociación —',
            ])
            ->add('fromMonth', DateType::class, $month + ['constraints' => [new Assert\NotNull(message: 'Escribe desde qué mes.')]])
            ->add('untilMonth', DateType::class, $month + ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Assert\Callback(self::validate(...))],
        ]);
    }

    /**
     * Lo que no se ve campo a campo: no puede terminar antes de empezar. (La
     * iniciativa de una reducción no es un error del formulario: el campo se esconde
     * al elegir «de menos» y el controller la descarta.)
     *
     * @param array{fromMonth: ?\DateTimeImmutable, untilMonth: ?\DateTimeImmutable}|null $data
     */
    public static function validate(?array $data, ExecutionContextInterface $context): void
    {
        if ($data === null) {
            return;
        }
        $from = $data['fromMonth'] ?? null;
        $until = $data['untilMonth'] ?? null;
        if ($from !== null && $until !== null && $until->format('Y-m') < $from->format('Y-m')) {
            $context->buildViolation('No puede terminar antes de empezar.')->atPath('[untilMonth]')->addViolation();
        }
    }
}
