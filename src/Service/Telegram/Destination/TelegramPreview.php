<?php

namespace App\Service\Telegram\Destination;

/**
 * Lo que un apartado ha entendido de un envío y enseña antes de guardarlo
 * ({@see ConfirmedDestination}).
 */
final class TelegramPreview
{
    /**
     * @param string      $question   Lo entendido y la pregunta: «Caja de la huerta, 30/10/2026:
     *                                456,50 € contados. ¿Está bien?».
     * @param string|null $transcript De una nota de voz, lo entendido por escrito, para guardar
     *                                eso mismo al confirmar. Null si no hay audio que leer.
     */
    public function __construct(
        public readonly string $question,
        public readonly ?string $transcript = null,
    ) {
    }
}
