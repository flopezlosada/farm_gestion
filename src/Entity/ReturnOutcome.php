<?php

namespace App\Entity;

/**
 * Cómo se cierra un recibo devuelto. Mientras no se elige ninguna, la devolución es
 * una deuda abierta de esa persona.
 */
enum ReturnOutcome: string
{
    /** Se le vuelve a pasar en una remesa: la cuenta estaba mal, o la deuda se ha resuelto. */
    case Recharge = 'recharge';

    /** Lo pagó por otro lado (transferencia, caja): se enlaza a ese apunte. */
    case PaidApart = 'paid_apart';

    /** No había que cobrárselo. */
    case NotOwed = 'not_owed';

    /** No se va a cobrar: una baja que no paga. */
    case WrittenOff = 'written_off';

    /** Cómo se llama en la pantalla. */
    public function label(): string
    {
        return match ($this) {
            self::Recharge => 'Volver a cobrar',
            self::PaidApart => 'Pagado aparte',
            self::NotOwed => 'No se debía',
            self::WrittenOff => 'Se da por perdido',
        };
    }
}
