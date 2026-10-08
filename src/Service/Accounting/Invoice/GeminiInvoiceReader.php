<?php

namespace App\Service\Accounting\Invoice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lee una factura (foto o PDF) con la API de Gemini y devuelve sus datos.
 *
 * POR QUÉ GEMINI Y EN SU PLAN GRATUITO: el módulo tiene que poder arrancar sin
 * costes. En el Espacio Económico Europeo el plan gratuito se rige por las
 * condiciones del de pago, así que Google no usa las facturas para entrenar ni las
 * revisa nadie. Cuando el módulo esté asentado se puede cambiar de proveedor: todo
 * lo que sabe de Gemini está en esta clase.
 *
 * UNA CADENA DE MODELOS, NO UNO. En el plan gratuito cada modelo tiene su propio
 * cupo y, cuando hay mucha demanda, Google contesta 503 a los gratuitos antes que a
 * nadie. Si uno está saturado o sin cupo se prueba el siguiente; sólo cuando fallan
 * todos se aplaza la factura. Un error de la propia factura (formato ilegible) no
 * pasa al siguiente modelo: fallaría igual en todos y gastaría cupo.
 */
class GeminiInvoiceReader
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * Lo que Gemini entiende viendo el documento. DOCX u ODT los leería como texto
     * suelto, y con un formato raro es mejor que lo complete una persona que
     * proponer algo dudoso.
     */
    public const READABLE_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    /** Respuestas que se arreglan esperando o con otro modelo. */
    private const TRANSIENT_STATUSES = [408, 429, 500, 502, 503, 504];

    /**
     * @param HttpClientInterface $http   Cliente HTTP.
     * @param string              $apiKey Clave de la API (vacía = lectura apagada).
     * @param list<string>        $models Modelos por orden de preferencia.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%app.invoice_reader.models%')]
        private readonly array $models,
    ) {
    }

    /** Si hay clave con la que leer. Sin ella, las facturas esperan en la cola. */
    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && $this->models !== [];
    }

    /** Si el formato es de los que se leen solos. */
    public static function canRead(string $mimeType): bool
    {
        return \in_array($mimeType, self::READABLE_MIME_TYPES, true);
    }

    /**
     * Lee el documento.
     *
     * @param string             $path       Ruta del fichero.
     * @param string             $mimeType   Tipo de contenido.
     * @param array<int, string> $categories Partidas que puede proponer: id => «GRUPO / Partida».
     *
     * @throws InvoiceReadException Si no se ha podido leer; dice si merece reintento.
     */
    public function read(string $path, string $mimeType, array $categories): InvoiceReading
    {
        if (!$this->isConfigured()) {
            throw new InvoiceReadException('La lectura automática no está configurada.', true);
        }
        if (!self::canRead($mimeType)) {
            throw new InvoiceReadException('Este formato no se lee solo: completa los datos a mano.', false);
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvoiceReadException('No se encuentra el fichero en el servidor.', false);
        }

        $body = $this->requestBody(base64_encode($content), $mimeType, $categories);
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
                // La clave no vale: todos los modelos dirán lo mismo. Se reintenta
                // más tarde porque arreglar la clave no exige volver a subir nada.
                throw new InvoiceReadException('El servicio de lectura rechaza la clave configurada.', true);
            }
            if ($status >= 400) {
                throw new InvoiceReadException('El documento no se ha podido leer: completa los datos a mano.', false);
            }

            return new InvoiceReading(ExtractedInvoice::fromArray($this->decode($payload)), $model);
        }

        throw new InvoiceReadException($lastProblem, true);
    }

    /**
     * La petición: el documento, las instrucciones y la forma exacta de la respuesta.
     * Pedir un esquema evita tener que interpretar texto libre.
     *
     * @param array<int, string> $categories id => etiqueta.
     *
     * @return array<string, mixed>
     */
    private function requestBody(string $base64, string $mimeType, array $categories): array
    {
        $catalogue = implode("\n", array_map(
            static fn (int $id, string $label): string => sprintf('%d: %s', $id, $label),
            array_keys($categories),
            $categories,
        ));

        $prompt = <<<TXT
            Extrae los datos de esta factura o ticket que ha recibido la asociación CSA Vega de Jarama.
            El cliente es la asociación; el proveedor es quien emite el documento.
            Importes en euros, con punto decimal. Si un dato no aparece, devuélvelo como null: no lo inventes.
            La dirección, el código postal, el municipio y la provincia son los del PROVEEDOR, nunca los de la asociación.
            "retencion_irpf" es el importe de la retención de IRPF que se descuenta del total (facturas de profesionales o alquileres), en positivo; null si no lleva.
            En "partida_id" elige el número de la partida de gasto que mejor encaje con lo comprado, de esta lista:
            {$catalogue}
            TXT;

        $nullableString = ['type' => 'STRING', 'nullable' => true];

        return [
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'tipo' => ['type' => 'STRING', 'enum' => ['factura', 'factura_simplificada', 'ticket', 'otro']],
                        'fecha' => ['type' => 'STRING', 'nullable' => true, 'description' => 'Fecha de la factura, AAAA-MM-DD'],
                        'proveedor' => $nullableString,
                        'cif_proveedor' => $nullableString,
                        'numero_factura' => $nullableString,
                        'lineas_iva' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'base' => ['type' => 'NUMBER'],
                                    'tipo_iva' => ['type' => 'NUMBER'],
                                    'cuota' => ['type' => 'NUMBER'],
                                ],
                            ],
                        ],
                        'retencion_irpf' => ['type' => 'NUMBER', 'nullable' => true],
                        'tipo_retencion' => ['type' => 'NUMBER', 'nullable' => true, 'description' => 'Tipo de la retención en %'],
                        'direccion_proveedor' => ['type' => 'STRING', 'nullable' => true, 'description' => 'Calle y número del proveedor'],
                        'cp_proveedor' => ['type' => 'STRING', 'nullable' => true, 'description' => 'Código postal del proveedor, 5 cifras'],
                        'municipio_proveedor' => $nullableString,
                        'provincia_proveedor' => $nullableString,
                        'total' => ['type' => 'NUMBER', 'nullable' => true],
                        'forma_pago' => ['type' => 'STRING', 'enum' => ['tarjeta', 'efectivo', 'transferencia', 'domiciliacion', 'desconocida']],
                        'concepto' => ['type' => 'STRING', 'nullable' => true, 'description' => 'Qué se compró, en pocas palabras'],
                        'partida_id' => ['type' => 'INTEGER', 'nullable' => true],
                        'confianza' => ['type' => 'STRING', 'enum' => ['alta', 'media', 'baja']],
                    ],
                    'required' => ['tipo', 'proveedor', 'total', 'forma_pago', 'confianza'],
                ],
            ],
        ];
    }

    /**
     * Saca el JSON de la respuesta. Si el modelo no ha devuelto nada utilizable, la
     * factura no tiene arreglo esperando: va a mano.
     *
     * @param array<string, mixed> $payload Respuesta de la API.
     *
     * @return array<string, mixed>
     */
    private function decode(array $payload): array
    {
        $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $data = \is_string($text) ? json_decode($text, true) : null;

        if (!\is_array($data)) {
            throw new InvoiceReadException('La lectura no ha devuelto datos: completa la factura a mano.', false);
        }

        return $data;
    }
}
