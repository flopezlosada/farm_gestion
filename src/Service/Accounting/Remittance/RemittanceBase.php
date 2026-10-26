<?php

namespace App\Service\Accounting\Remittance;

/**
 * A quién se le pasa una remesa de importe fijo: una derrama o una cuota de socix.
 */
enum RemittanceBase: string
{
    /** Todo socix, tenga cesta o no: hay quien paga cuota sin llevar cesta. */
    case Members = 'members';

    /** Quien paga cesta ese mes, una vez aunque pague también la de otras personas. */
    case BasketPayers = 'basket';

    /** Cómo se llama en la pantalla. */
    public function label(): string
    {
        return match ($this) {
            self::Members => 'Socixs',
            self::BasketPayers => 'Socixs con cesta',
        };
    }
}
