<?php

namespace App\Service\Accounting\Invoice;

/**
 * La lectura de una factura no ha salido.
 *
 * Distingue lo que se arregla esperando (el servicio está saturado, se acabó el cupo
 * del día, no hay conexión) de lo que no (el fichero no es legible). Lo primero se
 * reintenta; lo segundo pasa directamente a completarse a mano, porque insistir sólo
 * gasta cupo.
 */
final class InvoiceReadException extends \RuntimeException
{
    /**
     * @param string $message   Por qué, en palabras que se puedan enseñar en la bandeja.
     * @param bool   $retryable Si tiene sentido volver a intentarlo más tarde.
     */
    public function __construct(string $message, private readonly bool $retryable)
    {
        parent::__construct($message);
    }

    /** Si tiene sentido volver a intentarlo más tarde. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
