<?php

namespace App\Form;

use App\Service\Accounting\Invoice\InvoiceFileStore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Subir facturas a la bandeja: una o varias de golpe, que es como llegan cuando se
 * descargan los adjuntos del correo.
 */
class ReceivedInvoiceUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('files', FileType::class, [
            'multiple' => true,
            'constraints' => [
                new Assert\Count(min: 1, minMessage: 'Elige al menos una factura.'),
                new Assert\All([InvoiceFileStore::constraint()]),
            ],
            'attr' => ['accept' => implode(',', InvoiceFileStore::MIME_TYPES)],
        ]);
    }
}
