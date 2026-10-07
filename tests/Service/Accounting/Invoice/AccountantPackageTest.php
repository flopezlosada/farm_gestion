<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\AccountantPackage;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se llama cada factura dentro del ZIP de la gestoría y qué observaciones
 * lleva en el libro. Los nombres copian el patrón con que se renombran hoy a mano.
 */
class AccountantPackageTest extends TestCase
{
    private int $nextId = 1;

    /** «<Proveedor> Fact <nº>», sin la barra del número, que sería una carpeta. */
    public function testElNombreSigueElPatronDeLaGestoria(): void
    {
        $invoice = $this->invoice(['proveedor' => 'LA GRANJA DE IBAI SL', 'numero_factura' => '194/26'], 'granja-0413bcf2.pdf');

        $this->assertSame('LA GRANJA DE IBAI SL Fact 194-26.pdf', AccountantPackage::fileNames([$invoice])[$invoice->getId()]);
    }

    /** Lo pagado con tarjeta lo dice el nombre, como hoy, porque el banco lo carga aparte. */
    public function testConTarjetaLoDiceElNombre(): void
    {
        $invoice = $this->invoice(['proveedor' => 'Ferretería Torrelaguna', 'numero_factura' => '2232', 'forma_pago' => 'tarjeta'], 'f.JPG');

        $this->assertSame('Ferretería Torrelaguna Fact 2232 pago tarjeta.jpg', AccountantPackage::fileNames([$invoice])[$invoice->getId()]);
    }

    /** Un ticket sin número se nombra por su fecha; uno sin nada, no se queda sin nombre. */
    public function testSinNumeroVaLaFecha(): void
    {
        $ticket = $this->invoice(['proveedor' => 'Gasolinera', 'fecha' => '2026-05-03'], 'ticket.pdf');
        $empty = $this->invoice([], 'nada.pdf');

        $names = AccountantPackage::fileNames([$ticket, $empty]);
        $this->assertSame('Gasolinera 2026-05-03.pdf', $names[$ticket->getId()]);
        $this->assertSame('Sin proveedor sin fecha.pdf', $names[$empty->getId()]);
    }

    /** Dos que se llamarían igual no se pisan en el ZIP: la segunda lleva «(2)». */
    public function testDosIgualesNoSePisan(): void
    {
        $a = $this->invoice(['proveedor' => 'Movistar', 'numero_factura' => 'A1'], 'a.pdf');
        $b = $this->invoice(['proveedor' => 'MOVISTAR', 'numero_factura' => 'A1'], 'b.pdf');

        $names = AccountantPackage::fileNames([$a, $b]);
        $this->assertSame('Movistar Fact A1.pdf', $names[$a->getId()]);
        $this->assertSame('MOVISTAR Fact A1 (2).pdf', $names[$b->getId()], 'Iguales sin distinguir mayúsculas: Windows los trata como el mismo fichero.');
    }

    /** Las observaciones: desglose que no cuadra, sin desglose, documento que falta. */
    public function testObservacionesParaLaGestoria(): void
    {
        $fine = $this->invoice(['total' => 26.75, 'lineas_iva' => [['base' => 22.11, 'tipo_iva' => 21, 'cuota' => 4.64]]], 'a.pdf');
        $off = $this->invoice(['total' => 30.00, 'lineas_iva' => [['base' => 22.11, 'tipo_iva' => 21, 'cuota' => 4.64]]], 'b.pdf');
        $bare = $this->invoice(['total' => 10.00], 'c.pdf');

        $this->assertSame([], AccountantPackage::warnings($fine, '/ruta'));
        $this->assertSame(['el desglose suma 26,75 y el total es 30,00'], AccountantPackage::warnings($off, '/ruta'));
        $this->assertSame(['sin desglose de IVA', 'falta el documento'], AccountantPackage::warnings($bare, null));
    }

    /**
     * Una factura leída con estos datos (en el formato de la lectura) y un id, que es
     * con lo que se indexan los nombres.
     *
     * @param array<string, mixed> $read
     */
    private function invoice(array $read, string $fileName): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice($fileName, $fileName, 'application/pdf', 'web', null);
        $invoice->markRead(ExtractedInvoice::fromArray($read), 'test', null, new \DateTimeImmutable());
        (new \ReflectionProperty(ReceivedInvoice::class, 'id'))->setValue($invoice, $this->nextId++);

        return $invoice;
    }
}
