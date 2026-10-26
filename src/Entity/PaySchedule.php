<?php

namespace App\Entity;

/**
 * En cuántas pagas se cobra un sueldo. El valor es el número de pagas: el bruto anual
 * entre él da lo que se cobra en cada nómina ordinaria.
 */
enum PaySchedule: int
{
    /** Doce nóminas iguales: las pagas extra van prorrateadas en cada una. */
    case Twelve = 12;

    /** Doce nóminas más una paga extra en julio y otra en diciembre. */
    case Fourteen = 14;

    /** Cómo se llama en la pantalla. */
    public function label(): string
    {
        return match ($this) {
            self::Twelve => '12 pagas (extras prorrateadas)',
            self::Fourteen => '14 pagas (extras en julio y diciembre)',
        };
    }
}
