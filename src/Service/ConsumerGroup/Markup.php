<?php

namespace App\Service\ConsumerGroup;

use App\Entity\MarkupType;

/**
 * Incremento de gestión que la comisión suma al precio del productor: un tipo (% o €)
 * y un valor no negativo. Inmutable y siempre válido: un valor negativo no se puede
 * construir, así que el precio final nunca queda por debajo del del productor.
 *
 * Toda la aritmética va en céntimos enteros (sin floats con decimales) para que el
 * redondeo sea exacto y repetible.
 */
final class Markup
{
    private function __construct(
        public readonly MarkupType $type,
        public readonly string $value,
    ) {
    }

    /**
     * Construye el incremento a partir de lo guardado, que puede estar a medias.
     *
     * @return self|null null si falta el tipo o el valor (sin incremento definido)
     * @throws \InvalidArgumentException si el valor es negativo
     */
    public static function fromStored(?MarkupType $type, ?string $value): ?self
    {
        if ($type === null || $value === null) {
            return null;
        }
        if ((float) $value < 0) {
            throw new \InvalidArgumentException('El incremento no puede ser negativo.');
        }

        return new self($type, number_format((float) $value, 2, '.', ''));
    }

    /**
     * Precio final por unidad: precio del productor + incremento, a céntimos.
     * El porcentaje se redondea al céntimo más cercano (mitad hacia arriba).
     *
     * @param string $producerPrice precio del productor, decimal como string
     * @return string precio final con dos decimales
     */
    public function applyTo(string $producerPrice): string
    {
        $producerCents = self::toCents($producerPrice);
        $markupCents = match ($this->type) {
            MarkupType::Amount => self::toCents($this->value),
            // céntimos del productor × (% × 100) / 10000, redondeado mitad hacia arriba.
            MarkupType::Percent => intdiv($producerCents * self::toCents($this->value) * 2 + 10000, 20000),
        };

        return self::fromCents($producerCents + $markupCents);
    }

    /**
     * Pasa un decimal en string a céntimos enteros.
     */
    public static function toCents(string $decimal): int
    {
        return (int) round((float) $decimal * 100);
    }

    /**
     * Pasa céntimos enteros a un decimal en string con dos decimales.
     */
    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
