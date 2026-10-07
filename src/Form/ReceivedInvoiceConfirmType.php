<?php

namespace App\Form;

use App\Entity\AccountEntry;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
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
            ->add('entry', AccountEntryType::class, ['mapped' => false, 'data' => $options['entry'], 'constraints' => [new Assert\Valid()]])
            ->add('provider', EntityType::class, [
                'class' => Provider::class,
                'mapped' => false,
                'required' => false,
                'data' => $options['provider'],
                'placeholder' => 'Nuevo proveedor',
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReceivedInvoice::class, 'provider' => null]);
        $resolver->setRequired('entry');
        $resolver->setAllowedTypes('entry', AccountEntry::class);
        $resolver->setAllowedTypes('provider', [Provider::class, 'null']);
    }
}
