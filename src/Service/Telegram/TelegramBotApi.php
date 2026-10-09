<?php

namespace App\Service\Telegram;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lo poco que la aplicación necesita de la API de bots de Telegram, sin librería:
 * son peticiones HTTP con JSON y no compensa una dependencia para cinco métodos.
 *
 * Sin token configurado el bot está apagado ({@see isEnabled()}) y nadie debe
 * llamar al resto; si lo hace, recibe una {@see TelegramApiException}.
 *
 * El token va en la URL porque así lo pide Telegram. Por eso los errores que se
 * lanzan desde aquí NUNCA llevan la URL ni encadenan la excepción del cliente
 * HTTP, cuyo mensaje sí la lleva: acabarían en un log con el token dentro.
 */
class TelegramBotApi
{
    private const BASE = 'https://api.telegram.org';

    /**
     * Lo más grande que deja descargar la API pública de bots (getFile). Lo que
     * pese más ni se intenta.
     */
    public const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    /**
     * @param HttpClientInterface $http  Cliente HTTP.
     * @param string              $token Token del bot; vacío = apagado.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')]
        private readonly string $token,
    ) {
    }

    /** Si hay bot configurado. */
    public function isEnabled(): bool
    {
        return trim($this->token) !== '';
    }

    /**
     * El secreto que Telegram manda en cada llamada al webhook y que el webhook
     * exige. Sale del token para no tener otra clave que guardar: quien no tiene
     * el token no puede calcularlo, y si el token cambia, cambia con él.
     *
     * Hexadecimal: Telegram sólo admite letras, cifras, _ y - (hasta 256).
     */
    public function webhookSecret(): string
    {
        return hash_hmac('sha256', 'telegram-webhook', trim($this->token));
    }

    /**
     * Quién es el bot: sirve para saber su @nombre (el enlace de invitación lo
     * necesita) y, de paso, para comprobar que el token vale.
     *
     * @return array{id: int, username: string, first_name: string}
     *
     * @throws TelegramApiException Si el token no vale o no hay conexión.
     */
    public function getMe(): array
    {
        return $this->call('getMe', []);
    }

    /**
     * Manda un mensaje de texto a un chat.
     *
     * @param string $chatId Chat de destino.
     * @param string $text   Texto, sin formato.
     *
     * @throws TelegramApiException Si Telegram lo rechaza o no hay conexión.
     */
    public function sendMessage(string $chatId, string $text): void
    {
        $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }

    /**
     * Los mensajes pendientes, para leerlos a mano (en local, donde Telegram no
     * puede llamar a la aplicación). Espera hasta `$timeout` segundos a que llegue
     * alguno: es lo que Telegram llama «long polling».
     *
     * @param int $offset  Id del primer mensaje que se quiere; los anteriores se dan por leídos.
     * @param int $timeout Segundos de espera si no hay nada.
     *
     * @return list<array<string, mixed>> Los «updates» tal como los da Telegram.
     *
     * @throws TelegramApiException Si hay un webhook puesto, el token no vale o no hay conexión.
     */
    public function getUpdates(int $offset, int $timeout): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message'],
        ], $timeout + 10);
    }

    /**
     * Le dice a Telegram a qué dirección mandar los mensajes, con el secreto que
     * el webhook exigirá.
     *
     * @param string $url Dirección pública del webhook (https).
     *
     * @throws TelegramApiException Si Telegram la rechaza.
     */
    public function setWebhook(string $url): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $this->webhookSecret(),
            'allowed_updates' => ['message'],
        ]);
    }

    /**
     * Quita el webhook: los mensajes vuelven a quedarse esperando a getUpdates.
     *
     * @throws TelegramApiException Si Telegram lo rechaza.
     */
    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', []);
    }

    /**
     * Cómo está el webhook según Telegram: dirección, mensajes en cola y el último
     * error que tuvo al llamarlo.
     *
     * @return array<string, mixed>
     *
     * @throws TelegramApiException Si no hay conexión.
     */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', []);
    }

    /**
     * Descarga un fichero que alguien ha mandado al bot a un fichero temporal.
     *
     * @param string $fileId Id del fichero en Telegram.
     *
     * @return string Ruta del fichero temporal. Quien llama se encarga de él.
     *
     * @throws TelegramApiException Si no se puede descargar.
     */
    public function download(string $fileId): string
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);
        $path = $file['file_path'] ?? null;
        if (!\is_string($path) || $path === '') {
            throw new TelegramApiException('Telegram no da el fichero (¿pesa más de 20 MB?).');
        }

        $target = tempnam(sys_get_temp_dir(), 'tg-');
        try {
            $response = $this->http->request('GET', sprintf('%s/file/bot%s/%s', self::BASE, trim($this->token), $path), ['timeout' => 30]);
            if ($response->getStatusCode() !== 200) {
                throw new TelegramApiException(sprintf('Telegram no deja descargar el fichero (HTTP %d).', $response->getStatusCode()));
            }
            $handle = fopen($target, 'wb');
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }
            fclose($handle);
        } catch (ExceptionInterface $e) {
            @unlink($target);

            throw new TelegramApiException('No se ha podido descargar el fichero de Telegram.');
        } catch (TelegramApiException $e) {
            @unlink($target);

            throw $e;
        }

        return $target;
    }

    /**
     * Llama a un método de la API y devuelve su `result`.
     *
     * @param string               $method  Método de la API.
     * @param array<string, mixed> $params  Parámetros.
     * @param int                  $timeout Segundos máximos.
     *
     * @return array<mixed>
     *
     * @throws TelegramApiException Si el bot está apagado, Telegram contesta con error o no hay conexión.
     */
    private function call(string $method, array $params, int $timeout = 15): array
    {
        if (!$this->isEnabled()) {
            throw new TelegramApiException('El bot de Telegram no está configurado.');
        }

        try {
            $response = $this->http->request('POST', sprintf('%s/bot%s/%s', self::BASE, trim($this->token), $method), [
                'json' => $params,
                'timeout' => $timeout,
            ]);
            // false: Telegram explica el error en el cuerpo también con 4xx.
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            // Sin encadenar $e: su mensaje lleva la URL, y la URL lleva el token.
            throw new TelegramApiException(sprintf('No se ha podido hablar con Telegram (%s).', $method));
        }

        if (($data['ok'] ?? false) !== true) {
            throw new TelegramApiException(sprintf('Telegram rechaza %s: %s', $method, (string) ($data['description'] ?? 'sin motivo')));
        }

        return \is_array($data['result'] ?? null) ? $data['result'] : [];
    }
}
