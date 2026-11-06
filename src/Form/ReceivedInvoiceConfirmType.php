<?php

namespace App\Form;

use App\Entity\AccountEntry;
use App\Entity\FinancialAccount;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\GroupedPayment;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Convertir una factura en apunte: el formulario de apunte de siempre, ya relleno
 * con lo leído, más lo que es de la factura y no del apunte (quién la emite,
 * desglose de IVA, retención).
 *
 * El proveedor se ELIGE (`provider`, ya elegido si el CIF leído es de uno conocido)
 * o se deja en «nuevo»: sólo entonces cuentan el CIF y la dirección tecleados, que
 * son los datos con que se le da de alta. La ficha de un proveedor que ya existe no
 * se cambia desde aquí, se cambia en su propia pantalla.
 *
 * Si el pago ya está en el libro (lo trajo el extracto del banco), `match` ofrece
 * esos apuntes, el primero elegido: la factura se engancha a él en vez de crear otro
 * y contar el gasto dos veces. Entonces el apunte nuevo no se usa y su bloque se
 * oculta; para que no falle por algo que no se ve, sus datos se toman del elegido.
 * También ofrece (`grouped`) el pago de esta factura junto con otras del mismo
 * proveedor que siguen en la bandeja: al elegirlo, se enganchan todas.
 *
 * Pagada en caja o con tarjeta, se acepta con su apunte nuevo. Pagada por banco, se
 * acepta sin él: el pago lo trae el extracto y se engancha a ella al anotarlo. Del
 * bloque del apunte cuentan entonces la partida y el importe; la fecha de la factura
 * pasa a ser obligatoria, porque no hay apunte que la sitúe en su trimestre.
 *
 * Reutiliza {@see AccountEntryType} entero en vez de copiar sus campos: así el
 * signo, las partidas que se ofrecen y la validación son exactamente los mismos
 * que al anotar a mano. El apunte va sin mapear (opción `entry`) porque todavía no
 * es de la factura: lo será al confirmar.
 */
class ReceivedInvoiceConfirmType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $money = ['input' => 'string', 'scale' => 2, 'html5' => true, 'required' => false, 'attr' => ['step' => '0.01', 'min' => '0']];

        $builder
            // Sin mapear, el validador no recorre el apunte por su cuenta (sólo valida
            // los datos del formulario raíz): Valid le aplica las reglas de la entidad.
            ->add('entry', AccountEntryType::class, ['mapped' => false, 'data' => $options['entry'], 'with_provider' => false, 'constraints' => [new Assert\Valid()]])
            ->add('provider', EntityType::class, [
                'class' => Provider::class,
                'mapped' => false,
                'required' => false,
                'data' => $options['provider'],
                'placeholder' => 'Elige uno…',
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
                'choice_label' => static fn (Provider $p) => $p->getTaxId() ? sprintf('%s · %s', $p->getName(), $p->getTaxId()) : $p->getName(),
                // Lo que la pantalla enseña de la ficha elegida, sin otra petición.
                'choice_attr' => static fn (Provider $p) => [
                    'data-id' => $p->getId(),
                    'data-name' => $p->getName(),
                    'data-tax-id' => $p->getTaxId() ?? '',
                    'data-place' => implode(' · ', array_filter([
                        $p->getAddress(),
                        trim(sprintf('%s %s', $p->getPostalCode(), $p->getTown())),
                        $p->getProvince(),
                    ], static fn ($v) => $v !== null && trim((string) $v) !== '')),
                ],
            ])
            ->add('invoiceDate', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('providerTaxId', TextType::class, ['required' => false])
            ->add('providerAddress', TextType::class, ['required' => false])
            ->add('providerPostalCode', TextType::class, ['required' => false, 'attr' => ['inputmode' => 'numeric', 'maxlength' => 5]])
            ->add('providerTown', TextType::class, ['required' => false])
            ->add('providerProvince', TextType::class, ['required' => false])
            ->add('taxLines', CollectionType::class, [
                'entry_type' => ReceivedInvoiceTaxLineType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                // Sin esto los errores de una línea suben al formulario raíz, que la
                // plantilla no pinta junto a la línea.
                'error_bubbling' => false,
            ])
            ->add('withholding', NumberType::class, array_replace($money, ['attr' => ['step' => '0.01', 'min' => '0', 'data-inv-withholding' => '1']]))
            ->add('withholdingRate', NumberType::class, $money);

        // Los pagos agrupados van detrás de los de importe exacto, y su etiqueta dice
        // con qué otras facturas de la bandeja va.
        $withOthers = [];
        foreach ($options['grouped'] as $group) {
            $withOthers[(string) $group->payment->getId()] = $group->others;
        }
        $candidates = [...$options['candidates'], ...array_map(static fn (GroupedPayment $g): AccountEntry => $g->payment, $options['grouped'])];

        // Aceptada sin pago (se paga por banco), nada más la sitúa en su trimestre:
        // sin la fecha de la factura no entraría en ningún paquete de la gestoría.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            if (self::awaitsPayment($form) && $form->get('invoiceDate')->getData() === null) {
                $form->get('invoiceDate')->addError(new FormError(
                    'Pon la fecha de la factura: se paga por banco y es la que la lleva a su trimestre.',
                ));
            }
        });

        if ($candidates === []) {
            return;
        }

        $builder->add('match', ChoiceType::class, [
            'mapped' => false,
            'required' => false,
            'expanded' => true,
            'choices' => $candidates,
            'choice_value' => static fn (?AccountEntry $e) => $e?->getId(),
            'choice_label' => static fn (AccountEntry $e) => sprintf(
                '%s · %s · %s · %s €',
                $e->getDate()?->format('d/m/Y'),
                $e->getAccount()?->getName(),
                $e->getConcept(),
                number_format((float) $e->getAmount(), 2, ',', '.'),
            ).(isset($withOthers[(string) $e->getId()]) ? ' · junto con '.self::listOf($withOthers[(string) $e->getId()]) : ''),
            'placeholder' => 'Es otro pago: anotar un apunte nuevo',
            'data' => $candidates[0],
        ]);

        // Enganchada a un apunte que ya existe, lo que diga el bloque oculto del
        // apunte nuevo no cuenta: se rellena con el elegido para que valide siempre.
        // Proveedor, nº de factura y notas sí son de quien revisa y no se tocan.
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[(string) $candidate->getId()] = $candidate;
        }
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($byId): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }
            $match = $byId[(string) ($data['match'] ?? '')] ?? null;
            if ($match === null) {
                return;
            }
            $data['entry'] = array_replace($data['entry'] ?? [], [
                'date' => $match->getDate()?->format('Y-m-d'),
                'account' => (string) $match->getAccount()?->getId(),
                'category' => (string) $match->getCategory()?->getId(),
                'concept' => $match->getConcept(),
                'direction' => (float) $match->getAmount() >= 0 ? AccountEntryType::IN : AccountEntryType::OUT,
                'magnitude' => number_format(abs((float) $match->getAmount()), 2, '.', ''),
            ]);
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReceivedInvoice::class, 'provider' => null, 'candidates' => [], 'grouped' => []]);
        $resolver->setRequired('entry');
        $resolver->setAllowedTypes('entry', AccountEntry::class);
        $resolver->setAllowedTypes('provider', [Provider::class, 'null']);
        $resolver->setAllowedTypes('candidates', AccountEntry::class.'[]');
        $resolver->setAllowedTypes('grouped', GroupedPayment::class.'[]');
    }

    /**
     * Si se acepta sin apunte, esperando el pago: no se engancha a uno que ya esté en
     * el libro y se paga por una cuenta de banco, cuyo pago trae el extracto. Una caja
     * o una tarjeta no la trae ningún extracto movimiento a movimiento (la tarjeta
     * llega al banco liquidada de golpe): con ellas se crea el apunte.
     *
     * @param FormInterface $form El formulario entero, ya enviado.
     */
    public static function awaitsPayment(FormInterface $form): bool
    {
        $matched = $form->has('match') && $form->get('match')->getData() !== null;
        $account = $form->get('entry')->get('account')->getData();

        return !$matched && $account instanceof FinancialAccount && $account->isBank();
    }

    /**
     * «la factura 23 (12,00 €)», o «las facturas 23 (12,00 €) y 24 (30,00 €)». Sin
     * número, la fecha.
     *
     * @param list<ReceivedInvoice> $invoices
     */
    private static function listOf(array $invoices): string
    {
        $names = array_map(static fn (ReceivedInvoice $i): string => sprintf(
            '%s (%s €)',
            $i->getInvoiceNumber() ?? 'del '.$i->getInvoiceDate()?->format('d/m/Y'),
            number_format((float) $i->getTotal(), 2, ',', '.'),
        ), $invoices);
        $last = array_pop($names);

        return ($names === [] ? 'la factura ' : 'las facturas '.implode(', ', $names).' y ').$last;
    }
}
