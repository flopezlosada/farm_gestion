<?php

namespace App\Service\Telegram\Destination;

use App\Service\Ai\GeminiClient;
use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramInput;
use Psr\Log\LoggerInterface;

/**
 * Lo común a los apartados que entienden algo DICHO al bot, por voz o por escrito
 * (un encargo de factura, un traspaso entre cajas): qué mensajes lo son y cómo se le
 * enseñan a Gemini.
 */
final class Dictation
{
    /** Lo que graba Telegram en las notas de voz, si no dice otra cosa. */
    private const VOICE_MIME_TYPE = 'audio/ogg';

    /** Si es una nota de voz o un texto. Un comando («/ayuda») no es nada dicho. */
    public static function isDictated(TelegramInput $input): bool
    {
        return $input->kind === TelegramInput::VOICE
            || ($input->kind === TelegramInput::TEXT && !str_starts_with(ltrim($input->text), '/'));
    }

    /**
     * Lo dicho, como partes de una petición a Gemini: el audio, si lo hay, y el texto.
     *
     * @param TelegramInput   $input  Lo recibido.
     * @param LoggerInterface $logger Rastro si el audio no se puede descargar.
     *
     * @return list<array<string, mixed>>
     *
     * @throws TelegramRejected Si la nota de voz no se puede descargar de Telegram.
     */
    public static function parts(TelegramInput $input, LoggerInterface $logger): array
    {
        $parts = [];
        if ($input->kind === TelegramInput::VOICE) {
            try {
                $parts[] = GeminiClient::filePart((string) file_get_contents($input->localPath()), $input->mimeType ?? self::VOICE_MIME_TYPE);
            } catch (TelegramApiException $e) {
                $logger->warning('Telegram: no se pudo descargar una nota de voz', ['error' => $e->getMessage()]);
                throw new TelegramRejected($e->getMessage());
            }
        }
        if (trim($input->text) !== '') {
            $parts[] = ['text' => 'Lo que ha escrito: «' . $input->text . '»'];
        }

        return $parts;
    }
}
