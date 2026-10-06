<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use PHPUnit\Framework\TestCase;

/**
 * Lo que devuelve la lectura es texto de fuera: aquí se comprueba que sólo llega a
 * la base lo que tiene forma de dato, y que lo demás se queda en null en vez de
 * colarse.
 */
class ExtractedInvoiceTest extends TestCase
{
    /** Una lectura buena, tal como la devolvió Gemini con la factura de la ferretería. */
    public function testUnaLecturaBuenaPasaEntera(): void
    {
        $data = ExtractedInvoice::fromArray([
            'tipo' => 'factura',
            'fecha' => '2026-01-22',
            'proveedor' => 'FERRETERIA TORRELAGUNA',
            'cif_proveedor' => '51454945N',
            'numero_factura' => '2232',
            'lineas_iva' => [['base' => 22.11, 'tipo_iva' => 21, 'cuota' => 4.64]],
            'total' => 26.75,
            'forma_pago' => 'tarjeta',
            'concepto' => 'Lima, llave de bujías y tornillería',
            'partida_id' => 20,
            'confianza' => 'alta',
        ]);

        $this->assertSame('factura', $data->documentType);
        $this->assertSame('2026-01-22', $data->date?->format('Y-m-d'));
        $this->assertSame('51454945N', $data->providerTaxId);
        $this->assertSame('26.75', $data->total);
        $this->assertSame([['base' => 22.11, 'tipo_iva' => 21.0, 'cuota' => 4.64]], $data->taxLines);
        $this->assertSame(ReceivedInvoice::PAYMENT_CARD, $data->paymentMethod);
        $this->assertSame(20, $data->categoryId);
    }

    /**
     * Fechas imposibles o en otro formato, enumerados inventados y un total que no es
     * un número: nada de eso se guarda.
     */
    public function testLoQueNoTieneFormaDeDatoSeQuedaEnNull(): void
    {
        $data = ExtractedInvoice::fromArray([
            'tipo' => 'albaran',
            'fecha' => '2026-02-30',
            'proveedor' => '   ',
            'total' => 'veintiséis',
            'forma_pago' => 'bizum',
            'confianza' => 'muchísima',
            'lineas_iva' => 'no es una lista',
            'partida_id' => 'veinte',
        ]);

        $this->assertNull($data->documentType);
        $this->assertNull($data->date);
        $this->assertNull($data->providerName);
        $this->assertNull($data->total);
        $this->assertNull($data->confidence);
        $this->assertSame([], $data->taxLines);
        $this->assertNull($data->categoryId);
        $this->assertSame(ReceivedInvoice::PAYMENT_UNKNOWN, $data->paymentMethod, 'Una forma de pago inventada se queda en «no consta».');
    }

    /** El CIF se compara sin espacios, guiones ni minúsculas: así se reconoce al proveedor. */
    public function testElCifSeNormaliza(): void
    {
        $data = ExtractedInvoice::fromArray(['cif_proveedor' => ' b-86 850 963 ']);

        $this->assertSame('B86850963', $data->providerTaxId);
    }

    /** Las lecturas reales traen campos a null: no pueden romper nada. */
    public function testUnaLecturaCasiVaciaNoRompe(): void
    {
        $data = ExtractedInvoice::fromArray(['cif_proveedor' => null, 'numero_factura' => null, 'total' => null]);

        $this->assertNull($data->providerTaxId);
        $this->assertNull($data->invoiceNumber);
        $this->assertNull($data->total);
    }
}
