<?php

namespace App\Service\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lo que la aplicación necesita de la API de Gemini: mandar un documento (o un
 * audio, o sólo texto) con unas instrucciones y recibir un JSON con la forma pedida.
 *
 * POR QUÉ GEMINI Y EN SU PLAN GRATUITO: la asociación tiene que poder arrancar sin
 * costes. En el Espacio Económico Europeo el plan gratuito se rige por las
 * condiciones del de pago, así que Google no usa lo que se le manda para entrenar
 * ni lo revisa nadie. Todo lo que sabe de Gemini la aplicación está aquí: cambiar
 * de proveedor es cambiar esta clase.
 *
 * UNA CADENA DE MODELOS, NO UNO. En el plan gratuito cada modelo tiene su propio
 * cupo y, cuando hay mucha demanda, Google contesta 503 a los gratuitos antes que a
 * nadie. Si uno está saturado o sin cupo se prueba el siguiente; sólo cuando fallan
 * todos se da por no disponible. Un rechazo del propio documento no pasa al
 * siguiente modelo: fallaría igual en todos y gastaría cupo.
 *
 * La clave va en cabecera, nunca en la URL, para que no acabe en ningún log.
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Respuestas que se arreglan esperando o con otro modelo. */
    private const TRANSIENT_STATUSES = [408, 429, 500, 502, 503, 504];

    /**
     * @param HttpClientInterface $http   Cliente HTTP.
     * @param string              $apiKey Clave de la API (vacía = apagado).
     * @param list<string>        $models Modelos por orden de preferencia.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%app.gemini.models%')]
        private readonly array $models,
    ) {
    }

    /** Si hay clave y modelos con los que trabajar. */
    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && $this->models !== [];
    }

    /**
     * Un fichero como parte de la petición.
     *
     * @param string $content  Contenido binario.
     * @param string $mimeType Tipo de contenido.
     *
     * @return array<string, mixed>
     */
    public static function filePart(string $content, string $mimeType): array
    {
        return ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($content)]];
    }

    /**
     * Pide un JSON con la forma de `$schema`. Pedir un esquema evita tener que
     * interpretar texto libre.
     *
     * @param list<array<string, mixed>> $parts  Partes: ficheros ({@see filePart()}) y `['text' => …]`.
     * @param array<string, mixed>       $schema Esquema de la respuesta, en el formato de Gemini.
     *
     * @return array{data: array<string, mixed>, model: string} Lo devuelto y qué modelo lo hizo.
     *
     * @throws GeminiException Si no se ha obtenido; dice por qué.
     */
    public function generateJson(array $parts, array $schema): array
    {
        if (!$this->isConfigured()) {
            throw new GeminiException(GeminiException::NOT_CONFIGURED, 'La lectura automática no está configurada.');
        }

        $body = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];
        $lastProblem = 'El servicio de lectura no responde.';

        foreach ($this->models as $model) {
            try {
                $response = $this->http->request('POST', sprintf(self::ENDPOINT, $model), [
                    'headers' => ['x-goog-api-key' => $this->apiKey],
                    'json' => $body,
                    'timeout' => 50,
                ]);
                $status = $response->getStatusCode();
                // Sólo una respuesta buena trae JSON: un 429 o un 503 suelen venir
                // vacíos o en HTML, y decodificarlos taparía el motivo real del fallo.
                $payload = $status < 400 ? $response->toArray(false) : [];
            } catch (TransportExceptionInterface) {
                $lastProblem = 'No hay conexión con el servicio de lectura.';
                continue;
            } catch (\Throwable) {
                $lastProblem = 'El servicio de lectura ha devuelto algo que no se entiende.';
                continue;
            }

            if (\in_array($status, self::TRANSIENT_STATUSES, true)) {
                $lastProblem = $status === 429
                    ? 'Se ha agotado el cupo gratuito de lectura por ahora.'
                    : 'El servicio de lectura está saturado.';
                continue;
            }
            if ($status === 401 || $status === 403) {
                // La clave no vale: todos los modelos dirán lo mismo.
                throw new GeminiException(GeminiException::KEY_REJECTED, 'El servicio de lectura rechaza la clave configurada.');
            }
            if ($status >= 400) {
                throw new GeminiException(GeminiException::REJECTED, 'El servicio de lectura no ha podido procesar el documento.');
            }

            return ['data' => $this->decode($payload), 'model' => $model];
        }

        throw new GeminiException(GeminiException::UNAVAILABLE, $lastProblem);
    }

    /**
     * Saca el JSON de la respuesta.
     *
     * @param array<string, mixed> $payload Respuesta de la API.
     *
     * @return array<string, mixed>
     *
     * @throws GeminiException Si no trae un JSON utilizable.
     */
    private function decode(array $payload): array
    {
        $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $data = \is_string($text) ? json_decode($text, true) : null;

        if (!\is_array($data)) {
            throw new GeminiException(GeminiException::NO_DATA, 'La lectura no ha devuelto datos.');
        }

        return $data;
    }
}
