<?php

namespace App\Service\Telegram;

use App\Service\Telegram\Destination\TelegramDestination;

/**
 * Lo que opina el clasificador de un envío: a qué apartado va y si lo tiene claro.
 * Sólo cuando lo tiene claro se guarda sin preguntar.
 */
final class TelegramClassification
{
    /**
     * @param TelegramDestination|null $destination El apartado que cree; null si no encaja en ninguno.
     * @param bool                     $sure        Si no tiene dudas.
     */
    public function __construct(
        public readonly ?TelegramDestination $destination,
        public readonly bool $sure,
    ) {
    }

    /** Si se puede guardar sin preguntar. */
    public function isClear(): bool
    {
        return $this->destination !== null && $this->sure;
    }
}
