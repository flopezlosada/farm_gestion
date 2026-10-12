<?php

namespace App\Tests\Service\Accounting\IssuedInvoice;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use App\Service\Accounting\IssuedInvoice\DictatedInvoiceReader;
use App\Service\Ai\GeminiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Del encargo dictado al borrador: el cliente sólo si de verdad se le ha nombrado,
 * la base calculada aquí cuando se dicta con IVA, y lo que no se entiende se deja
 * fuera en vez de inventarlo.
 */
class DictatedInvoiceReaderTest extends TestCase
{
    /** @var list<array<string, mixed>> Cuerpos que ha recibido Gemini. */
    private array $sent = [];

    /** El encargo real de IMIDRA 10/2026: sale la misma factura, al céntimo. */
    public function testElEncargoDeImidraSaleComoLaFacturaReal(): void
    {
        $dictated = $this->reader([
            'cliente' => 'c7', 'cliente_oido' => 'el Imidra', 'serie' => 'ordinary', 'fecha' => '2026-06-30',
            'lineas' => [
                ['concepto' => '20 horas de formación', 'importe' => 1600, 'iva' => '0'],
                ['concepto' => 'Gastos de estancia', 'importe' => 1272, 'iva' => '21'],
            ],
        ])->read([['text' => 'x']], [$this->imidra()], new \DateTimeImmutable('2026-10-12'), null);

        $invoice = $dictated->invoice;
        $this->assertTrue($invoice->isDraft(), 'el bot nunca emite');
        $this->assertSame(IssuedInvoice::SOURCE_TELEGRAM, $invoice->getSource());
        $this->assertSame('IMIDRA', $invoice->getCustomer()?->getName());
        $this->assertSame('2026-06-30', $invoice->getIssueDate()->format('Y-m-d'));
        $this->assertSame('3139.12', $invoice->getTotal());
        $this->assertNull($dictated->customerHeard);
    }

    /**
     * Con un solo cliente dado de alta, el esquema empuja a elegirlo aunque se hable
     * de otro: si lo oído no lo nombra, queda sin cliente y se dice qué se oyó.
     */
    public function testSiLoOidoNoNombraAlClienteQuedaSinCliente(): void
    {
        $dictated = $this->reader([
            'cliente' => 'c7', 'cliente_oido' => 'la cooperativa', 'serie' => 'ordinary',
            'lineas' => [['concepto' => 'Visita', 'importe' => 150, 'iva' => '0']],
        ])->read([['text' => 'x']], [$this->imidra()], new \DateTimeImmutable('2026-10-12'), null);

        $this->assertNull($dictated->invoice->getCustomer());
        $this->assertSame('la cooperativa', $dictated->customerHeard);
    }

    /** Gemini sólo puede contestar con los clientes dados de alta (o ninguno), y los ve en el texto. */
    public function testGeminiEligeSoloEntreLosClientes(): void
    {
        $this->reader(['cliente' => 'ninguno', 'cliente_oido' => '', 'serie' => 'x', 'lineas' => []])
            ->read([['text' => 'x']], [$this->imidra()], new \DateTimeImmutable('2026-10-12'), null);

        $request = $this->sent[0];
        $this->assertSame(['c7', 'ninguno'], $request['generationConfig']['responseSchema']['properties']['cliente']['enum']);
        $this->assertStringContainsString('c7: IMIDRA (CIF S7800035C)', end($request['contents'][0]['parts'])['text']);
    }

    /** Sin fecha (o con una que no lo es), la de hoy; las líneas sin concepto o con un tipo raro, fuera. */
    public function testLoQueNoSeEntiendeSeQuedaFuera(): void
    {
        $invoice = $this->reader([
            'cliente' => 'ninguno', 'cliente_oido' => '', 'serie' => 'x', 'fecha' => '30 de junio',
            'lineas' => [
                ['concepto' => '', 'importe' => 10, 'iva' => '21'],
                ['concepto' => 'Algo', 'importe' => 10, 'iva' => '7'],
                ['concepto' => 'Alquiler de la nave', 'importe' => 'mucho', 'iva' => '21'],
                ['concepto' => 'Alquiler de la nave', 'importe' => 242, 'iva' => '21', 'iva_incluido' => true],
            ],
        ])->read([['text' => 'x']], [], new \DateTimeImmutable('2026-10-12'), null)->invoice;

        $this->assertSame('2026-10-12', $invoice->getIssueDate()->format('Y-m-d'));
        $this->assertSame(IssuedInvoice::SERIES_X, $invoice->getSeries());
        $this->assertCount(1, $invoice->getLines());
        $this->assertSame('200.00', $invoice->getLines()->first()->getAmount(), '242 con el 21 % dentro');
    }

    /** La base la calcula PHP, no el modelo. */
    public function testLaBaseSeCalculaAquiSiSeDictaConIva(): void
    {
        $this->assertSame('200.00', DictatedInvoiceReader::base(242, '21', true));
        $this->assertSame('1272.00', DictatedInvoiceReader::base(1272, '21', false));
        $this->assertSame('150.00', DictatedInvoiceReader::base(150, '0', true), 'exenta: no hay IVA que quitar');
        $this->assertSame('82.64', DictatedInvoiceReader::base(100, '21', true), 'redondeo al céntimo');
    }

    /** Nombra al cliente una palabra distintiva o su CIF; «cooperativa» o «Madrid» no. */
    public function testQueEsNombrarAUnCliente(): void
    {
        $troje = (new Customer())->setName('Cooperativa La Troje')->setTaxId('F28000001');

        $this->assertTrue(DictatedInvoiceReader::names('la troje', $troje));
        $this->assertTrue(DictatedInvoiceReader::names('el de CIF F-28000001', $troje));
        $this->assertTrue(DictatedInvoiceReader::names('Imidra', $this->imidra()), 'sin mayúsculas');
        $this->assertFalse(DictatedInvoiceReader::names('la Cooperativa El Encinar', $troje), '«cooperativa» no distingue');
        $this->assertFalse(DictatedInvoiceReader::names(null, $troje));
    }

    private function imidra(): Customer
    {
        $customer = (new Customer())->setName('IMIDRA')->setTaxId('S-7800035-C');
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, 7);

        return $customer;
    }

    /**
     * @param array<string, mixed> $answer Lo que contesta Gemini.
     */
    private function reader(array $answer): DictatedInvoiceReader
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->sent[] = json_decode($options['body'], true);

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
            ]), ['http_code' => 200]);
        });

        return new DictatedInvoiceReader(new GeminiClient($http, 'clave', ['modelo-a']));
    }
}
