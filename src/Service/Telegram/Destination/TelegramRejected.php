<?php

namespace App\Service\Telegram\Destination;

/**
 * Un apartado no puede quedarse con un envío (formato que no admite, fichero que
 * no se puede descargar…). El mensaje se le enseña tal cual a quien lo mandó.
 */
final class TelegramRejected extends \RuntimeException
{
}
