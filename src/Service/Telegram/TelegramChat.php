<?php

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;

/**
 * La conversación con quien ha escrito: lo único que un destino necesita para
 * contestar, sin saber nada de la API de Telegram.
 */
final class TelegramChat
{
    /**
     * @param TelegramBotApi  $api    API del bot.
     * @param string          $chatId Chat.
     * @param LoggerInterface $logger Rastro de lo que falla.
     */
    public function __construct(
        private readonly TelegramBotApi $api,
        private readonly string $chatId,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Contesta. Si no se puede (la persona ha bloqueado el bot, Telegram caído), se
     * apunta y se sigue: lo que había que guardar ya está guardado.
     *
     * @param string $text Respuesta, sin formato.
     */
    public function say(string $text): void
    {
        try {
            $this->api->sendMessage($this->chatId, $text);
        } catch (TelegramApiException $e) {
            $this->logger->warning('Telegram: no se pudo contestar', ['error' => $e->getMessage()]);
        }
    }
}
