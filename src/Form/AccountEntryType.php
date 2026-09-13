<?php

namespace App\Form;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Repository\BudgetCategoryRepository;
use App\Repository\FinancialAccountRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Anotar un movimiento de dinero.
 *
 * El modelo guarda UN importe con signo, pero aquí no se teclea así: se elige si el
 * dinero entra o sale y se escribe la cantidad en positivo, que es como se trabaja
 * con un extracto delante y como está montado el Excel. La conversión vive en este
 * formulario, no en el controller, para que no haya dos sitios donde equivocarse con
 * el signo.
 *
 * Escribir «-150» donde se quería «150» es un error silencioso: el apunte se guarda,
 * el saldo baja el doble de lo que debía y nada chilla. Con dos campos, ese error no
 * se puede cometer.
 */
class AccountEntryType extends AbstractType
{
    /** El dinero entra en la cuenta. */
    public const IN = 'in';

    /** El dinero sale de la cuenta. */
    public const OUT = 'out';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('account', EntityType::class, [
                'class' => FinancialAccount::class,
                'choice_label' => 'name',
                'query_builder' => static fn (FinancialAccountRepository $r) => $r->createQueryBuilder('a')
                    ->andWhere('a.active = true')
                    ->orderBy('a.sortOrder', 'ASC'),
                'placeholder' => 'Elige la cuenta',
            ])
            ->add('category', EntityType::class, [
                'class' => BudgetCategory::class,
                'choice_label' => 'name',
                // Agrupadas por su grupo en el desplegable: hay casi cuarenta y dos
                // se llaman igual («Formación» es de ingresos y de gastos), así que
                // sin el grupo delante no se distinguen.
                'group_by' => static fn (BudgetCategory $c): string => $c->getGroup()?->getName() ?? '',
                'query_builder' => static fn (BudgetCategoryRepository $r) => $r->createQueryBuilder('c')
                    ->innerJoin('c.group', 'g')->addSelect('g')
                    ->andWhere('c.active = true')
                    ->orderBy('g.sortOrder', 'ASC')
                    ->addOrderBy('c.sortOrder', 'ASC'),
                'placeholder' => 'Elige la partida',
            ])
            ->add('concept', TextType::class, [
                'required' => true,
            ])
            ->add('direction', ChoiceType::class, [
                'mapped' => false,
                'expanded' => true,
                'choices' => [
                    'Sale de la cuenta' => self::OUT,
                    'Entra en la cuenta' => self::IN,
                ],
                'data' => self::OUT,
            ])
            ->add('magnitude', NumberType::class, [
                'mapped' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
                'constraints' => [
                    new Assert\NotNull(message: 'Pon el importe.'),
                    new Assert\Positive(message: 'El importe se escribe en positivo; el signo lo pone «entra» o «sale».'),
                ],
            ])
            ->add('providerName', TextType::class, ['required' => false])
            ->add('invoiceNumber', TextType::class, ['required' => false])
            ->add('notes', TextareaType::class, ['required' => false])
            ->add('submit', SubmitType::class);

        // Al abrir un apunte ya guardado, deshace el signo para llenar las dos
        // casillas.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $entry = $event->getData();
            if (!$entry instanceof AccountEntry || $entry->getId() === null) {
                return;
            }
            $amount = (float) $entry->getAmount();
            $event->getForm()->get('direction')->setData($amount >= 0 ? self::IN : self::OUT);
            $event->getForm()->get('magnitude')->setData(abs($amount));
        });

        // Al guardar, compone el importe con su signo.
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $entry = $event->getData();
            if (!$entry instanceof AccountEntry) {
                return;
            }
            $magnitude = (float) $event->getForm()->get('magnitude')->getData();
            $sign = $event->getForm()->get('direction')->getData() === self::IN ? 1 : -1;
            $entry->setAmount(number_format($sign * abs($magnitude), 2, '.', ''));
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountEntry::class,
        ]);
    }

    /**
     * Signo que sugiere cada partida, para que el formulario preseleccione «entra» o
     * «sale» según lo que se elija. Lo consume el JS de la plantilla: es una ayuda,
     * no una imposición, porque una devolución es un ingreso en negativo legítimo.
     *
     * @return array<int, string> id de partida => IN u OUT
     */
    public static function suggestedDirections(array $categories): array
    {
        $out = [];
        foreach ($categories as $category) {
            if (!$category instanceof BudgetCategory) {
                continue;
            }
            $sign = $category->getGroup()?->expectedSign() ?? 0;
            if ($sign !== 0) {
                $out[(int) $category->getId()] = $sign > 0 ? self::IN : self::OUT;
            }
        }

        return $out;
    }
}
