<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\RemittanceSheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * El Excel de recibos en los dos sentidos. Los ficheros de prueba imitan los reales:
 * hechos a mano, con un título encima, importes con coma decimal, fechas como número
 * de serie de Excel o como texto, una fila de totales al final y alguna vacía.
 */
class RemittanceSheetTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
    }

    /**
     * @param list<list<mixed>> $rows Filas desde la A1.
     */
    private function xlsx(array $rows): string
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'remesa') . '.xlsx';
        (new Xlsx($book))->save($path);
        $this->files[] = $path;

        return $path;
    }

    public function testLeeUnExcelHechoAMano(): void
    {
        $path = $this->xlsx([
            ['REMESA AGOSTO 2026'],
            [],
            ['Nombre deudor', 'Cuenta cargo', 'Referencia mandato', 'Fecha firma mandato', 'Referencia adeudo', 'Tipo de adeudo', 'Importe'],
            ['LÓPEZ GARCÍA ANA', 'ES91 2100 0418 4502 0005 1332', 'CUOTA MENSUAL 200', 44896, 'AGOSTO 2026 200', 'FRST', 65],
            ['PÉREZ RUIZ LUIS', 'ES7921000813610123456789', 'CUOTA MENSUAL 203', '01/11/2022', 'AGOSTO 2026 203', 'Primer adeudo de una serie de adeudos recurrentes', '43,5'],
            [null, null, null, null, null, null, null],
            ['MARTÍN SOL', 'ES1000492352082414205416', 'CUOTA MENSUAL 207', null, 'AGOSTO 2026 207', 'Adeudo de una serie de adeudos recurrentes', '1.200,00'],
            ['TOTAL', null, null, null, null, null, '=SUM(G4:G7)'],
        ]);

        $read = (new RemittanceSheet())->read($path);

        $this->assertSame([], $read['problems']);
        $this->assertCount(3, $read['lines']);
        [$ana, $luis, $sol] = $read['lines'];
        $this->assertSame('LÓPEZ GARCÍA ANA', $ana->getDebtorName());
        $this->assertSame('ES9121000418450200051332', $ana->getIban());
        $this->assertSame('2022-12-01', $ana->getMandateSignedOn()->format('Y-m-d'), '44896 es el número de serie de Excel del 1-12-2022.');
        $this->assertSame('65.00', $ana->getAmount());
        $this->assertSame('FRST', $ana->getSequenceType());
        $this->assertSame('2022-11-01', $luis->getMandateSignedOn()->format('Y-m-d'));
        $this->assertSame('43.50', $luis->getAmount());
        $this->assertSame('FRST', $luis->getSequenceType(), 'El rótulo del banco del primer adeudo también menciona «recurrentes».');
        $this->assertNull($sol->getMandateSignedOn(), 'Una fecha vacía se queda vacía; la remesa avisará antes de enviarse.');
        $this->assertSame('1200.00', $sol->getAmount());
        $this->assertSame('RCUR', $sol->getSequenceType());
        $this->assertNull($ana->getPartner(), 'Leer no asigna socix: eso lo hace el emparejador.');
    }

    /** Las columnas se buscan por su título: otro orden o una columna de más no cuela datos donde no van. */
    public function testLasColumnasSeBuscanPorTitulo(): void
    {
        $path = $this->xlsx([
            ['IMPORTE', 'Observaciones', 'NOMBRE DEUDOR', 'TIPO DE ADEUDO', 'CUENTA CARGO', 'REFERENCIA ADEUDO', 'REFERENCIA MANDATO', 'FECHA FIRMA MANDATO'],
            [70, 'paga también la de su madre', 'ANA', 'RCUR', 'ES9121000418450200051332', 'R-1', 'CUOTA MENSUAL 1', '15/03/2023'],
        ]);

        $line = (new RemittanceSheet())->read($path)['lines'][0];

        $this->assertSame('ANA', $line->getDebtorName());
        $this->assertSame('70.00', $line->getAmount());
        $this->assertSame('CUOTA MENSUAL 1', $line->getMandateReference());
        $this->assertSame('ES9121000418450200051332', $line->getIban());
    }

    public function testSinTodasLasColumnasNoDevuelveNada(): void
    {
        $path = $this->xlsx([
            ['NOMBRE DEUDOR', 'CUENTA CARGO', 'IMPORTE'],
            ['ANA', 'ES9121000418450200051332', 65],
        ]);

        $read = (new RemittanceSheet())->read($path);

        $this->assertSame([], $read['lines']);
        $this->assertStringContainsString('REFERENCIA MANDATO', $read['problems'][0]);
    }

    public function testSinFilaDeTitulosLoDice(): void
    {
        $read = (new RemittanceSheet())->read($this->xlsx([['ANA', 'ES9121000418450200051332', 65]]));

        $this->assertSame([], $read['lines']);
        $this->assertStringContainsString('fila de títulos', $read['problems'][0]);
    }

    /** Lo que no se entiende se dice con su fila, para que quien importa lo encuentre. */
    public function testUnImporteOUnaFechaQueNoSeEntiendenSonProblemas(): void
    {
        $path = $this->xlsx([
            RemittanceSheet::COLUMNS,
            ['ANA', 'ES9121000418450200051332', 'CUOTA MENSUAL 200', '01/11/2022', 'R-1', 'RCUR', 'sesenta'],
            ['LUIS', 'ES7921000813610123456789', 'CUOTA MENSUAL 201', 'noviembre', 'R-2', 'RCUR', 60],
        ]);

        $read = (new RemittanceSheet())->read($path);

        $this->assertSame([
            'Fila 2 (ANA): el importe «sesenta» no es un número.',
            'Fila 3 (LUIS): la fecha de firma «noviembre» no se entiende.',
        ], $read['problems']);
    }

    public function testNoEsUnaHojaDeCalculo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'remesa') . '.xlsx';
        file_put_contents($path, 'esto no es un excel');
        $this->files[] = $path;

        $read = (new RemittanceSheet())->read($path);

        $this->assertSame([], $read['lines']);
        $this->assertNotEmpty($read['problems']);
    }

    /**
     * Lo que se escribe se lee igual: las mismas columnas en el orden de siempre, la
     * cuenta como texto (si no, Excel la convierte en un número con exponente), la
     * fecha como fecha y sin fila de totales.
     */
    public function testLoQueSeEscribeSeLeeIgual(): void
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-09-01'), 'Recibos SEPTIEMBRE 2026', Remittance::SOURCE_APP, null);
        $remittance->addLine((new RemittanceLine())
            ->setDebtorName('LÓPEZ GARCÍA ANA')->setIban('ES9121000418450200051332')
            ->setMandateReference('CUOTA MENSUAL 200')->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'))
            ->setDebitReference('SEPTIEMBRE 2026 200')->setSequenceType('RCUR')->setAmount('43.50'));
        $remittance->addLine((new RemittanceLine())
            ->setDebtorName('PÉREZ RUIZ LUIS')->setIban('ES7921000813610123456789')
            ->setMandateReference('CUOTA MENSUAL 203')->setMandateSignedOn(new \DateTimeImmutable('2023-03-15'))
            ->setDebitReference('SEPTIEMBRE 2026 203')->setSequenceType('FRST')->setAmount('120.00'));

        $sheet = new RemittanceSheet();
        $path = tempnam(sys_get_temp_dir(), 'remesa') . '.xlsx';
        $this->files[] = $path;
        (new Xlsx($sheet->write($remittance)))->save($path);

        $raw = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        $this->assertSame(RemittanceSheet::COLUMNS, $raw[0]);
        $this->assertCount(3, $raw, 'Títulos y dos recibos: ni totales ni filas de más.');
        $this->assertSame('ES9121000418450200051332', $raw[1][1]);

        $back = $sheet->read($path);
        $this->assertSame([], $back['problems']);
        $this->assertSame(
            array_map(self::row(...), $remittance->getLines()->toArray()),
            array_map(self::row(...), $back['lines']),
        );
    }

    /** @return list<string|null> */
    private static function row(RemittanceLine $l): array
    {
        return [$l->getDebtorName(), $l->getIban(), $l->getMandateReference(), $l->getMandateSignedOn()?->format('Y-m-d'), $l->getDebitReference(), $l->getSequenceType(), $l->getAmount()];
    }
}
