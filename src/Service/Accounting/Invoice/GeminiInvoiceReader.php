<?php

namespace App\Service\Accounting\Invoice;

use App\Service\Ai\GeminiClient;
use App\Service\Ai\GeminiException;

/**
 * Lee una factura (foto o PDF) con Gemini y devuelve sus datos.
 *
 * Aquí está QUÉ se le pide (las instrucciones y la forma de la respuesta) y qué
 * hacer con cada fallo; CÓMO se habla con Gemini (modelos, cupo, errores) está en
 * {@see GeminiClient}, compartido con el resto de la aplicación.
 */
class GeminiInvoiceReader
{
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

    /**
     * @param GeminiClient $gemini Cliente de Gemini.
     */
    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /** Si hay clave con la que leer. Sin ella, las facturas esperan en la cola. */
    public function isConfigured(): bool
    {
        return $this->gemini->isConfigured();
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

        try {
            $result = $this->gemini->generateJson(
                [GeminiClient::filePart($content, $mimeType), ['text' => $this->prompt($categories)]],
                self::schema(),
            );
        } catch (GeminiException $e) {
            throw match ($e->reason) {
                GeminiException::REJECTED => new InvoiceReadException('El documento no se ha podido leer: completa los datos a mano.', false),
                GeminiException::NO_DATA => new InvoiceReadException('La lectura no ha devuelto datos: completa la factura a mano.', false),
                default => new InvoiceReadException($e->getMessage(), $e->isRetryable()),
            };
        }

        return new InvoiceReading(ExtractedInvoice::fromArray($result['data']), $result['model']);
    }

    /**
     * Las instrucciones, con las partidas entre las que elegir.
     *
     * @param array<int, string> $categories id => etiqueta.
     */
    private function prompt(array $categories): string
    {
        $catalogue = implode("\n", array_map(
            static fn (int $id, string $label): string => sprintf('%d: %s', $id, $label),
            array_keys($categories),
            $categories,
        ));

        return <<<TXT
            Extrae los datos de esta factura o ticket que ha recibido la asociación CSA Vega de Jarama.
            El cliente es la asociación; el proveedor es quien emite el documento.
            Importes en euros, con punto decimal. Si un dato no aparece, devuélvelo como null: no lo inventes.
            La dirección, el código postal, el municipio y la provincia son los del PROVEEDOR, nunca los de la asociación.
            "retencion_irpf" es el importe de la retención de IRPF que se descuenta del total (facturas de profesionales o alquileres), en positivo; null si no lleva.
            En "partida_id" elige el número de la partida de gasto que mejor encaje con lo comprado, de esta lista:
            {$catalogue}
            TXT;
    }

    /**
     * La forma exacta de la respuesta.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $nullableString = ['type' => 'STRING', 'nullable' => true];

        return [
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
        ];
    }
}
