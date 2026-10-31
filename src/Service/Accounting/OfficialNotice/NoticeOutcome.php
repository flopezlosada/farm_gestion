<?php

namespace App\Service\Accounting\OfficialNotice;

/**
 * Qué ha pasado con un correo del buzón al mirar si es un aviso de notificación
 * oficial.
 */
enum NoticeOutcome
{
    /** No viene de ningún organismo de la lista: sigue su camino como posible factura. */
    case NotOfficial;

    /** Del organismo, con su firma: se ha guardado y avisado. */
    case Alerted;

    /** Del organismo, pero de ese correo ya se avisó. */
    case Repeated;

    /** Dice venir del organismo y su firma no lo prueba: sólo queda en el log. */
    case Suspicious;

    /**
     * Si el correo dice venir de un organismo, sea verdad o no. Ninguno de ésos es una
     * factura: uno suplantado puede traer un adjunto dañino, y no debe acabar en la
     * bandeja de facturas ni en la lectura automática.
     *
     * @return bool true si no hay que tratarlo como factura.
     */
    public function isOfficial(): bool
    {
        return $this !== self::NotOfficial;
    }
}
