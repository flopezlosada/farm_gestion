<?php

namespace App\Service\Telegram;

/**
 * Lo que alguien ha mandado al bot, ya separado de la forma de los mensajes de
 * Telegram: qué es (foto, documento, nota de voz o texto), el texto o pie que lo
 * acompaña y, si trae fichero, cómo obtenerlo.
 *
 * El fichero NO se descarga hasta que alguien lo pide ({@see localPath()}), y una
 * sola vez aunque lo pidan el clasificador y luego el destino. Lo que no se usa no
 * se descarga: un mensaje de quien no puede usarlo no llega a tocar el disco.
 */
final class TelegramInput
{
    public const PHOTO = 'photo';
    public const DOCUMENT = 'document';
    public const VOICE = 'voice';
    public const TEXT = 'text';

    private ?string $localPath = null;

    /** @var (\Closure(string): string)|null */
    private ?\Closure $downloader = null;

    /**
     * @param string             $kind     Qué es: una de las constantes.
     * @param string             $text     Texto del mensaje o pie de foto ('' si no hay).
     * @param \DateTimeImmutable $sentAt   Cuándo se mandó.
     * @param string|null        $fileId   Id del fichero en Telegram, si trae.
     * @param string|null        $fileName Nombre del fichero (inventado para fotos y notas de voz).
     * @param int                $fileSize Tamaño según Telegram (0 si no lo dice).
     * @param string|null        $mimeType Tipo según Telegram, sin comprobar.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $text,
        public readonly \DateTimeImmutable $sentAt,
        public readonly ?string $fileId = null,
        public readonly ?string $fileName = null,
        public readonly int $fileSize = 0,
        public readonly ?string $mimeType = null,
    ) {
    }

    /**
     * Lo que se entiende de un mensaje de Telegram; null si no es nada de lo que el
     * bot sabe recibir (una pegatina, una ubicación…).
     *
     * @param array<string, mixed> $message Mensaje tal como lo da Telegram.
     */
    public static function fromMessage(array $message): ?self
    {
        $sentAt = (new \DateTimeImmutable())->setTimestamp((int) ($message['date'] ?? time()));
        $caption = trim((string) ($message['caption'] ?? ''));

        if (isset($message['document']['file_id'])) {
            $doc = $message['document'];

            return new self(self::DOCUMENT, $caption, $sentAt, (string) $doc['file_id'], (string) ($doc['file_name'] ?? 'documento'), (int) ($doc['file_size'] ?? 0), $doc['mime_type'] ?? null);
        }

        if (isset($message['photo']) && \is_array($message['photo']) && $message['photo'] !== []) {
            // Telegram manda varias copias de cada foto, de menor a mayor.
            $largest = end($message['photo']);

            return new self(self::PHOTO, $caption, $sentAt, (string) $largest['file_id'], sprintf('foto-telegram-%s.jpg', $sentAt->format('Y-m-d-His')), (int) ($largest['file_size'] ?? 0), 'image/jpeg');
        }

        if (isset($message['voice']['file_id'])) {
            $voice = $message['voice'];

            return new self(self::VOICE, $caption, $sentAt, (string) $voice['file_id'], sprintf('voz-telegram-%s.ogg', $sentAt->format('Y-m-d-His')), (int) ($voice['file_size'] ?? 0), $voice['mime_type'] ?? 'audio/ogg');
        }

        $text = trim((string) ($message['text'] ?? ''));

        return $text !== '' ? new self(self::TEXT, $text, $sentAt) : null;
    }

    /** Si trae fichero. */
    public function hasFile(): bool
    {
        return $this->fileId !== null;
    }

    /**
     * Le dice cómo descargar su fichero. Lo hace {@see TelegramBot}, que es quien
     * habla con Telegram; los destinos sólo piden {@see localPath()}.
     *
     * @param \Closure(string): string $downloader Recibe el id y devuelve la ruta local.
     */
    public function setDownloader(\Closure $downloader): void
    {
        $this->downloader = $downloader;
    }

    /**
     * Ruta del fichero en disco; lo descarga la primera vez. Quien lo guarde puede
     * MOVERLO: lo que quede aquí al terminar se borra.
     *
     * @throws TelegramApiException Si no trae fichero, pesa demasiado o no se puede descargar.
     */
    public function localPath(): string
    {
        if ($this->localPath !== null) {
            return $this->localPath;
        }
        if ($this->fileId === null || $this->downloader === null) {
            throw new TelegramApiException('Este mensaje no trae fichero.');
        }
        if ($this->fileSize > TelegramBotApi::MAX_DOWNLOAD_BYTES) {
            throw new TelegramApiException('Ese fichero pesa más de 20 MB y Telegram no deja que el bot lo descargue. Súbelo desde la web.');
        }

        return $this->localPath = ($this->downloader)($this->fileId);
    }

    /** Borra la copia descargada si nadie se la ha llevado. */
    public function cleanUp(): void
    {
        if ($this->localPath !== null && is_file($this->localPath)) {
            @unlink($this->localPath);
        }
    }
}
