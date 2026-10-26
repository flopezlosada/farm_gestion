<?php

namespace App\Entity;

/**
 * En qué punto está un recibo devuelto. No se guarda: sale de la salida elegida y de
 * lo enlazado ({@see RemittanceReturn::getStatus()}).
 */
enum ReturnStatus: string
{
    /** Deuda abierta: falta decidir qué se hace. */
    case Pending = 'pending';

    /** Se va a volver a cobrar y aún no está en ninguna remesa. */
    case ToRecharge = 'to_recharge';

    /** Ya está en un borrador de remesa, que falta enviar. */
    case Recharging = 'recharging';

    /** Resuelto. */
    case Closed = 'closed';

    /** Si todavía hay algo que hacer con él. */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    /** Cómo se llama en la pantalla. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::ToRecharge => 'Por volver a cobrar',
            self::Recharging => 'En un borrador de remesa',
            self::Closed => 'Resuelto',
        };
    }
}
