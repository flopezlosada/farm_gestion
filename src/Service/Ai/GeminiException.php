<?php

namespace App\Service\Ai;

/**
 * Gemini no ha devuelto lo pedido. Dice POR QUÉ, porque quien llama decide distinto
 * según el motivo: lo pasajero se reintenta; lo que es del documento, no.
 */
class GeminiException extends \RuntimeException
{
    /** Saturado, sin cupo o sin conexión: se arregla esperando. */
    public const UNAVAILABLE = 'unavailable';

    /** La clave no vale. Se arregla cambiándola, sin volver a mandar nada. */
    public const KEY_REJECTED = 'key_rejected';

    /** Gemini rechaza la petición (el documento no se puede procesar). */
    public const REJECTED = 'rejected';

    /** Ha contestado, pero sin el JSON pedido. */
    public const NO_DATA = 'no_data';

    /** No hay clave configurada. */
    public const NOT_CONFIGURED = 'not_configured';

    /**
     * @param string $reason  Uno de los motivos de arriba.
     * @param string $message Explicación que se puede enseñar.
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Si merece volver a intentarlo más tarde. */
    public function isRetryable(): bool
    {
        return \in_array($this->reason, [self::UNAVAILABLE, self::KEY_REJECTED, self::NOT_CONFIGURED], true);
    }
}
