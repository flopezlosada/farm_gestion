<?php

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;

/**
 * La conversación con quien ha escrito: lo único que hace falta para contestar,
 * sin saber nada de la API de Telegram.
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
     * @param string                                  $text    Respuesta, sin formato.
     * @param list<list<array{0: string, 1: string}>> $buttons Filas de botones [texto, dato].
     *
     * @return int|null Id del mensaje, o null si no se pudo mandar.
     */
    public function say(string $text, array $buttons = []): ?int
    {
        try {
            return $this->api->sendMessage($this->chatId, $text, $buttons);
        } catch (TelegramApiException $e) {
            $this->logger->warning('Telegram: no se pudo contestar', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Cambia un mensaje ya enviado (para quitarle los botones cuando se resuelve).
     * Si no se puede (muy antiguo, ya borrado), se manda uno nuevo: lo importante
     * es que le llegue.
     *
     * @param int|null                                $messageId Mensaje a cambiar; null = mandar uno nuevo.
     * @param string                                  $text      Texto nuevo.
     * @param list<list<array{0: string, 1: string}>> $buttons   Botones nuevos (ninguno los quita).
     *
     * @return int|null Id del mensaje que queda con ese texto.
     */
    public function replace(?int $messageId, string $text, array $buttons = []): ?int
    {
        if ($messageId !== null) {
            try {
                $this->api->editMessageText($this->chatId, $messageId, $text, $buttons);

                return $messageId;
            } catch (TelegramApiException $e) {
                // Telegram rechaza cambiar un mensaje por el mismo texto y botones
                // (pulsar dos veces lo mismo): ya dice lo que tiene que decir.
                if (str_contains($e->getMessage(), 'message is not modified')) {
                    return $messageId;
                }
                $this->logger->info('Telegram: no se pudo cambiar un mensaje; se manda otro', ['error' => $e->getMessage()]);
            }
        }

        return $this->say($text, $buttons);
    }
}
