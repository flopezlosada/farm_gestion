<?php

namespace App\Service\Telegram;

use App\Service\Ai\GeminiClient;
use App\Service\Ai\GeminiException;
use App\Service\Telegram\Destination\TelegramDestination;
use Symfony\Component\Mime\MimeTypes;

/**
 * Decide a qué destino va lo que ha llegado al bot cuando hay más de uno posible:
 * le enseña a Gemini el fichero (si lo entiende), el texto o pie de foto, y la
 * descripción de cada candidato, y Gemini elige uno o ninguno.
 *
 * Elige SÓLO entre los candidatos: la respuesta está atada a sus claves en el
 * esquema, así que no puede inventarse un destino. «Ninguno» es una respuesta
 * válida y es lo que debe salir cuando no encaja claramente: mejor no guardar que
 * guardar en el sitio equivocado.
 */
class TelegramClassifier
{
    public const NONE = 'ninguno';

    /**
     * Lo más grande que se le enseña a Gemini para clasificar. Va en base64 dentro
     * de la petición, así que ocupa en memoria un tercio más; por encima de esto se
     * clasifica sólo por el nombre y el texto. Una factura o una foto de móvil caben.
     */
    private const MAX_INLINE_BYTES = 8 * 1024 * 1024;

    /**
     * Lo que se le enseña a Gemini como fichero. Del resto (un DOCX, una hoja de
     * cálculo) sólo ve el nombre y el texto que lo acompañe.
     *
     * El audio es lo que graba Telegram en las notas de voz (OGG). La API de Gemini
     * admite audio; que entienda bien una nota de voz real está POR PROBAR.
     */
    private const VISIBLE_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'audio/ogg',
        'audio/mpeg',
        'audio/mp4',
    ];

    /**
     * @param GeminiClient $gemini Cliente de Gemini.
     */
    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /** Si se puede clasificar: sin Gemini, con varios candidatos no hay forma de elegir. */
    public function isAvailable(): bool
    {
        return $this->gemini->isConfigured();
    }

    /**
     * Elige destino.
     *
     * @param TelegramInput             $input      Lo recibido.
     * @param list<TelegramDestination> $candidates Destinos posibles (dos o más).
     *
     * @return TelegramDestination|null El elegido, o null si no encaja en ninguno.
     *
     * @throws GeminiException      Si Gemini no ha contestado.
     * @throws TelegramApiException Si el fichero no se puede descargar.
     */
    public function choose(TelegramInput $input, array $candidates): ?TelegramDestination
    {
        $byKey = [];
        foreach ($candidates as $destination) {
            $byKey[$destination->key()] = $destination;
        }

        $parts = [];
        if ($input->hasFile()) {
            $path = $input->localPath();
            $mimeType = (string) MimeTypes::getDefault()->guessMimeType($path);
            if (\in_array($mimeType, self::VISIBLE_MIME_TYPES, true) && (int) filesize($path) <= self::MAX_INLINE_BYTES) {
                $parts[] = GeminiClient::filePart((string) file_get_contents($path), $mimeType);
            }
        }
        $parts[] = ['text' => $this->prompt($input, $candidates)];

        $result = $this->gemini->generateJson($parts, [
            'type' => 'OBJECT',
            'properties' => [
                'destino' => ['type' => 'STRING', 'enum' => [...array_keys($byKey), self::NONE]],
            ],
            'required' => ['destino'],
        ]);

        return $byKey[(string) ($result['data']['destino'] ?? self::NONE)] ?? null;
    }

    /**
     * Las instrucciones: qué ha llegado y entre qué elegir.
     *
     * @param TelegramInput             $input      Lo recibido.
     * @param list<TelegramDestination> $candidates Destinos posibles.
     */
    private function prompt(TelegramInput $input, array $candidates): string
    {
        $options = implode("\n", array_map(
            static fn (TelegramDestination $d): string => sprintf('- %s: %s', $d->key(), $d->description()),
            $candidates,
        ));
        $context = array_filter([
            $input->fileName !== null ? 'Nombre del fichero: ' . $input->fileName : null,
            $input->text !== '' ? 'Texto que lo acompaña: «' . $input->text . '»' : null,
        ]);

        return "Alguien de la asociación CSA Vega de Jarama (huerta ecológica que reparte cestas a sus socias) ha mandado esto al bot de la asociación.\n"
            . ($context !== [] ? implode("\n", $context) . "\n" : '')
            . "Elige a qué apartado va, según estas descripciones:\n{$options}\n"
            . 'Si no encaja claramente en ninguno, responde "' . self::NONE . '". No elijas uno por aproximación.';
    }
}
