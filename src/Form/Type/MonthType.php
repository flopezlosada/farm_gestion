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
 * `min` (opcional) es el primer mes que se puede elegir: limita el selector. La regla
 * de verdad va en las constraints de quien lo usa; esto sólo guía.
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
            'min' => null,
        ]);
        $resolver->setAllowedTypes('min', ['null', \DateTimeInterface::class]);
    }

    /**
     * Pinta el campo como selector de mes y, si hay `min`, no deja elegir antes.
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'month';
        $view->vars['attr']['placeholder'] ??= 'aaaa-mm';
        if ($options['min'] !== null) {
            $view->vars['attr']['min'] = $options['min']->format('Y-m');
        }
    }
}
