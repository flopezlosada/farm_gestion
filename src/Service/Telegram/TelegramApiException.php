<?php

namespace App\Service\Telegram;

/**
 * Telegram no ha hecho lo que se le pedía: bot apagado, token que no vale, sin
 * conexión o petición rechazada. El mensaje se puede enseñar y nunca lleva el token.
 */
class TelegramApiException extends \RuntimeException
{
}
