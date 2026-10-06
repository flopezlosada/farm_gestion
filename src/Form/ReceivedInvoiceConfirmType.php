<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Convertir una factura en apunte: el formulario de apunte de siempre, ya relleno
 * con lo leído, más el CIF del proveedor, que es de la factura y no del apunte.
 *
 * Reutiliza {@see AccountEntryType} entero en vez de copiar sus campos: así el
 * signo, las partidas que se ofrecen y la validación son exactamente los mismos
 * que al anotar a mano.
 */
class ReceivedInvoiceConfirmType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('entry', AccountEntryType::class)
            ->add('providerTaxId', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(max: 20)],
            ]);
    }
}
