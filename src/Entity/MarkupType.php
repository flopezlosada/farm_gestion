<?php

namespace App\Entity;

/**
 * Cómo se expresa el incremento de gestión que la comisión suma al precio del productor:
 * como porcentaje de ese precio o como euros por unidad. Se guarda junto a su valor
 * ({@see \App\Service\ConsumerGroup\Markup}).
 */
enum MarkupType: string
{
    /** Porcentaje sobre el precio del productor. */
    case Percent = 'percent';

    /** Euros por unidad. */
    case Amount = 'amount';
}
