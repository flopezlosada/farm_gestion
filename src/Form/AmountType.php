<?php

namespace App\Form;

use App\Form\DataTransformer\SpanishAmountTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Casilla de importe en euros que lee el servidor, no el navegador.
 *
 * Existe porque el `<input type="number">` deja la lectura del número al navegador:
 * rechaza «41.496,30», que es como se escribe un importe aquí, y según su idioma puede
 * rechazar también la coma o el punto decimal. El aviso sale del navegador, el
 * formulario no llega a enviarse y no hay nada en la app que lo explique. Aquí la
 * casilla es de texto (con teclado numérico en el móvil) y la lectura es una sola,
 * {@see SpanishAmountTransformer}.
 */
class AmountType extends AbstractType
{
    /**
     * Pone la lectura española entre lo escrito y el modelo.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addViewTransformer(new SpanishAmountTransformer());
    }

    /**
     * Teclado decimal en el móvil y un aviso que dice cómo escribirlo.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => 'Escribe un importe, por ejemplo 1.234,56.',
            'attr' => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
        ]);
    }

    /**
     * Una casilla de texto: el navegador no interpreta el número.
     */
    public function getParent(): string
    {
        return TextType::class;
    }
}
