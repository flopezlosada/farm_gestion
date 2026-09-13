<?php

namespace App\Form;

use App\Entity\ObligationTerm;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Anotar una renovación: un periodo nuevo de validez.
 *
 * Es el formulario que cierra el ciclo. Mientras alguien anote aquí la fecha
 * nueva, el sistema vuelve a vigilar solo y el aviso del año que viene sale sin
 * que nadie lo programe.
 */
class ObligationTermType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startsOn', DateType::class, [
                'label' => 'Vigente desde',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('endsOn', DateType::class, [
                'label' => 'Caduca el',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'A partir de aquí se cuentan los avisos.',
            ])
            ->add('document', ObligationDocumentType::class, [
                'label' => 'Documento firmado',
                'help' => 'El papel de esta renovación. Si todavía no está escaneado, se puede subir después desde el historial.',
            ])
            ->add('notes', TextType::class, [
                'label' => 'Apunte',
                'required' => false,
                'help' => 'Opcional: «se firmó con la addenda de riegos», «subió la prima»…',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ObligationTerm::class,
        ]);
    }
}
