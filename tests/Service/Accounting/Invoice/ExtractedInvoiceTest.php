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
            'direccion_proveedor' => 'C/ Mayor 12',
            'cp_proveedor' => '28180',
            'municipio_proveedor' => 'Torrelaguna',
            'provincia_proveedor' => 'Madrid',
            'retencion_irpf' => null,
            'forma_pago' => 'tarjeta',
            'concepto' => 'Lima, llave de bujías y tornillería',
            'partida_id' => 20,
            'confianza' => 'alta',
        ]);

        $this->assertSame('factura', $data->documentType);
        $this->assertSame('2026-01-22', $data->date?->format('Y-m-d'));
        $this->assertSame('51454945N', $data->providerTaxId);
        $this->assertSame('26.75', $data->total);
        $this->assertSame([['base' => '22.11', 'rate' => '21.00', 'taxAmount' => '4.64']], $data->taxLines);
        $this->assertSame('28180', $data->providerPostalCode);
        $this->assertSame('Torrelaguna', $data->providerTown);
        $this->assertSame('Madrid', $data->providerProvince);
        $this->assertNull($data->withholding);
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

    /**
     * Una factura de profesional con retención: el importe llega en positivo aunque
     * la factura lo imprima restando, y un código postal leído como número recupera
     * el cero inicial que pierde un CP de Albacete.
     */
    public function testRetencionEnPositivoYCodigoPostalConCero(): void
    {
        $data = ExtractedInvoice::fromArray([
            'retencion_irpf' => -45.0,
            'tipo_retencion' => 15,
            'cp_proveedor' => 2639,
            'lineas_iva' => [
                ['base' => 300, 'tipo_iva' => 21, 'cuota' => 63],
                ['base' => null, 'tipo_iva' => null, 'cuota' => null],
                ['base' => 50, 'tipo_iva' => null, 'cuota' => null],
            ],
        ]);

        $this->assertSame('45.00', $data->withholding);
        $this->assertSame('15.00', $data->withholdingRate);
        $this->assertSame('02639', $data->providerPostalCode);
        $this->assertCount(2, $data->taxLines, 'La línea sin cifras se descarta; la que va a medias se queda para completarla.');
        $this->assertSame(['base' => '50.00', 'rate' => null, 'taxAmount' => null], $data->taxLines[1]);
    }

    /** Un código postal que no son cinco cifras no se guarda. */
    public function testCodigoPostalSinFormaSeDescarta(): void
    {
        $this->assertNull(ExtractedInvoice::fromArray(['cp_proveedor' => 'Madrid'])->providerPostalCode);
        $this->assertNull(ExtractedInvoice::fromArray(['retencion_irpf' => 0])->withholding, 'Retención cero = no lleva.');
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
