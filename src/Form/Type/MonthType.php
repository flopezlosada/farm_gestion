<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un mes («2026-12»), con el selector de mes del navegador.
 *
 * DateType no sabe pintar `type="month"`: con `html5 => false` escribe
 * `type="text"`, y poner el tipo en `attr` lo DUPLICA; el navegador se queda con el
 * primero y no sale calendario. Este tipo lo fija en la vista.
 *
 * Firefox de escritorio no tiene selector de mes: ahí sale como texto «aaaa-mm».
 * Si el mes tiene que estar acotado, mejor una lista de meses.
 */
class MonthType extends AbstractType
{
    public function getParent(): string
    {
        return DateType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'widget' => 'single_text',
            'html5' => false,
            'format' => 'yyyy-MM',
            'input' => 'datetime_immutable',
        ]);
    }

    /** Pinta el campo como selector de mes. */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'month';
        $view->vars['attr']['placeholder'] ??= 'aaaa-mm';
    }
}
