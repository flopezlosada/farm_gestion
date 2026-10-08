<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Service\Accounting\Invoice\GeminiInvoiceReader;
use App\Service\Accounting\Invoice\InvoiceReadException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Cómo se comporta la lectura ante lo que de verdad pasa con el plan gratuito de
 * Gemini: modelos saturados (503), cupo agotado (429), ficheros ilegibles. Lo que
 * importa es la decisión: pasar al siguiente modelo, aplazar o mandar a mano. Un
 * error en esa decisión o gasta cupo para nada o deja facturas en el limbo.
 */
class GeminiInvoiceReaderTest extends TestCase
{
    private string $pdf;

    protected function setUp(): void
    {
        $this->pdf = sys_get_temp_dir() . '/factura-test-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->pdf);
    }

    public function testUnaLecturaBuenaDevuelveLosDatosYElModelo(): void
    {
        $reader = $this->reader([$this->ok(['proveedor' => 'Movistar', 'total' => 17, 'forma_pago' => 'domiciliacion', 'tipo' => 'factura', 'confianza' => 'alta'])]);

        $reading = $reader->read($this->pdf, 'application/pdf', [14 => 'ADMINISTRACIÓN / Teléfono']);

        $this->assertSame('modelo-a', $reading->model);
        $this->assertSame('Movistar', $reading->invoice->providerName);
        $this->assertSame('17.00', $reading->invoice->total);
    }

    /** Lo que pasó en la prueba real: el primer modelo saturado y el segundo sí lee. */
    public function testSiUnModeloEstaSaturadoPruebaElSiguiente(): void
    {
        $reader = $this->reader([
            new MockResponse('{"error":{"code":503}}', ['http_code' => 503]),
            $this->ok(['proveedor' => 'Ferretería', 'total' => 26.75, 'forma_pago' => 'tarjeta', 'tipo' => 'factura', 'confianza' => 'alta']),
        ]);

        $reading = $reader->read($this->pdf, 'application/pdf', []);

        $this->assertSame('modelo-b', $reading->model);
    }

    /** Si todos están saturados o sin cupo, la factura se aplaza: se arregla esperando. */
    public function testSiTodosFallanPorSaturacionSeAplaza(): void
    {
        $reader = $this->reader([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 429]),
        ]);

        try {
            $reader->read($this->pdf, 'application/pdf', []);
            $this->fail('Debía fallar.');
        } catch (InvoiceReadException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertStringContainsString('cupo', $e->getMessage(), 'El aviso tiene que decir el motivo del último intento.');
        }
    }

    /**
     * Un 400 es un problema del documento: los demás modelos fallarían igual y
     * gastarían cupo. No se prueba el siguiente ni se reintenta.
     */
    public function testUnDocumentoIlegibleVaAManoSinGastarMasCupo(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"error":{"code":400}}', ['http_code' => 400]),
            $this->ok(['proveedor' => 'No debería llegar aquí']),
        ]);
        $reader = new GeminiInvoiceReader($client, 'clave', ['modelo-a', 'modelo-b']);

        try {
            $reader->read($this->pdf, 'application/pdf', []);
            $this->fail('Debía fallar.');
        } catch (InvoiceReadException $e) {
            $this->assertFalse($e->isRetryable());
        }
        $this->assertSame(1, $client->getRequestsCount());
    }

    /** Un DOCX se guarda, pero no se manda a leer: se completa a mano. */
    public function testUnFormatoQueNoSeLeeNoLlegaAGastarUnaPeticion(): void
    {
        $client = new MockHttpClient([]);
        $reader = new GeminiInvoiceReader($client, 'clave', ['modelo-a']);

        try {
            $reader->read($this->pdf, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', []);
            $this->fail('Debía fallar.');
        } catch (InvoiceReadException $e) {
            $this->assertFalse($e->isRetryable());
        }
        $this->assertSame(0, $client->getRequestsCount());
    }

    /** Sin clave no se lee nada y la factura espera: no es culpa suya. */
    public function testSinClaveNoSeLlamaYSeAplaza(): void
    {
        $client = new MockHttpClient([]);
        $reader = new GeminiInvoiceReader($client, '', ['modelo-a']);

        $this->assertFalse($reader->isConfigured());
        try {
            $reader->read($this->pdf, 'application/pdf', []);
            $this->fail('Debía fallar.');
        } catch (InvoiceReadException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $this->assertSame(0, $client->getRequestsCount());
    }

    /** La clave va en una cabecera, no en la URL: las URL acaban en los logs. */
    public function testLaClaveNoViajaEnLaUrl(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['url' => $url, 'key' => $options['normalized_headers']['x-goog-api-key'][0] ?? null];

            return $this->ok(['proveedor' => 'x', 'total' => 1, 'forma_pago' => 'efectivo', 'tipo' => 'ticket', 'confianza' => 'alta']);
        });
        (new GeminiInvoiceReader($client, 'secreta', ['modelo-a']))->read($this->pdf, 'application/pdf', []);

        $this->assertStringNotContainsString('secreta', $seen['url']);
        $this->assertSame('x-goog-api-key: secreta', $seen['key']);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function reader(array $responses): GeminiInvoiceReader
    {
        return new GeminiInvoiceReader(new MockHttpClient($responses), 'clave', ['modelo-a', 'modelo-b']);
    }

    /** Respuesta de Gemini con el JSON pedido dentro, como la devuelve la API. */
    private function ok(array $data): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]],
        ]), ['http_code' => 200]);
    }
}
