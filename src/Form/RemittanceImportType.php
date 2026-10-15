<?php

namespace App\Form;

use App\Entity\Remittance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Subir un Excel de recibos que ya se envió al banco, para tenerlo como histórico y
 * preparar el siguiente a partir de él. El mes y el nombre se proponen a partir del
 * nombre del fichero si no se escriben.
 */
class RemittanceImportType extends AbstractType
{
    /** Los formatos en que se guardan los Excel de recibos. */
    public const MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/zip',
        'application/octet-stream',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Excel de recibos',
                'constraints' => [
                    new Assert\NotNull(message: 'Elige el Excel.'),
                    new Assert\File(maxSize: '5M', mimeTypes: self::MIME_TYPES, mimeTypesMessage: 'Tiene que ser un Excel (.xlsx, .xls) u hoja de LibreOffice (.ods).'),
                ],
                'attr' => ['accept' => '.xlsx,.xls,.ods'],
            ])
            ->add('kind', ChoiceType::class, [
                'label' => 'Qué se cobró',
                'choices' => array_flip(Remittance::KIND_LABELS),
            ])
            ->add('period', DateType::class, [
                'label' => 'Mes',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'yyyy-MM',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['type' => 'month', 'placeholder' => 'aaaa-mm'],
            ])
            ->add('label', TextType::class, [
                'label' => 'Nombre',
                'required' => false,
                'attr' => ['maxlength' => 120],
            ]);
    }
}
