<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Un importe en euros como lo escribe la gente en España, sin depender del navegador.
 *
 * Acepta «41.496,30», «41496,30» y también «41496.30». Un punto seguido de grupos de
 * tres cifras es de miles («1.500» son mil quinientos); cualquier otro punto, sin coma
 * en el número, es el decimal. El modelo es la cadena decimal de Doctrine («41496.30»).
 */
class SpanishAmountTransformer implements DataTransformerInterface
{
    private const THOUSANDS = '/^-?\d{1,3}(\.\d{3})+(,\d+)?$/';
    private const COMMA_DECIMAL = '/^-?\d+(,\d+)?$/';
    private const DOT_DECIMAL = '/^-?\d+(\.\d+)?$/';

    /**
     * Del modelo a lo que se ve en la casilla: coma decimal y sin separador de miles.
     */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_numeric($value)) {
            throw new TransformationFailedException('El importe guardado no es un número.');
        }

        return number_format((float) $value, 2, ',', '');
    }

    /**
     * De lo escrito al modelo; null si la casilla está vacía.
     */
    public function reverseTransform(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = str_replace([' ', "\u{00A0}", '€'], '', trim((string) $value));
        if ($text === '') {
            return null;
        }

        $normalized = match (true) {
            preg_match(self::THOUSANDS, $text) === 1 => str_replace(['.', ','], ['', '.'], $text),
            preg_match(self::COMMA_DECIMAL, $text) === 1 => str_replace(',', '.', $text),
            preg_match(self::DOT_DECIMAL, $text) === 1 => $text,
            default => throw new TransformationFailedException(sprintf('«%s» no es un importe.', $text)),
        };

        return number_format((float) $normalized, 2, '.', '');
    }
}
