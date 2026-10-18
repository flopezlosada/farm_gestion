<?php

namespace App\Service\Backup;

/**
 * Backblaze B2 no ha hecho lo pedido. El mensaje se puede enseñar: nunca lleva la
 * clave ni encadena el error del cliente HTTP.
 */
class B2Exception extends \RuntimeException
{
    /**
     * @param string   $message Qué ha pasado, enseñable.
     * @param int|null $status  Código HTTP con que contestó B2; null si no hubo respuesta.
     */
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /**
     * Si merece reintentar con otra URL de subida: sin respuesta, servidor
     * ocupado o caído (5xx, 408) o token de subida caducado (401). Un 400 o un
     * 403 fallarían igual la segunda vez, subiendo el fichero entero otra vez.
     */
    public function isRetryable(): bool
    {
        return $this->status === null || $this->status >= 500 || \in_array($this->status, [401, 408], true);
    }
}
