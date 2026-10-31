<?php

namespace App\Tests\Service\Accounting\Norma43;

use App\Service\Accounting\Norma43\BankSpreadsheetReader;
use App\Service\Accounting\Norma43\BankStatementReader;
use App\Service\Accounting\Norma43\Norma43Account;
use App\Service\Accounting\Norma43\Norma43Exception;
use App\Service\Accounting\Norma43\Norma43Reader;
use PHPUnit\Framework\TestCase;

/**
 * Lectura de las hojas de movimientos en Excel que exporta la banca online (sintéticas,
 * cifras y nombres inventados).
 */
class BankSpreadsheetReaderTest extends TestCase
{
    /**
     * Como la de La Caixa (.xls y .xlsx): la cuenta sale del IBAN del título, los saldos
     * de la cadena y los textos de las columnas que no son fecha, importe ni saldo.
     *
     * @dataProvider formats
     */
    public function testHojaConIbanEnElTitulo(string $format): void
    {
        $raw = BankSheet::caixaLike('ES12 9999 0001 1100 1234 5678', 1500.00, [
            ['2091-03-05', -45.50, 'RECIBO LUZ'],
            ['2091-03-12', 30.00, 'TRANSF. A SU FAVOR'],
            ['2091-03-12', 30.00, 'TRANSF. A SU FAVOR'],
        ], $format);

        $this->assertTrue(BankSpreadsheetReader::looksLikeSpreadsheet($raw));
        $file = (new BankSpreadsheetReader())->read($raw);

        $this->assertSame([], $file->warnings);
        $this->assertCount(1, $file->accounts);
        $account = $file->accounts[0];
        $this->assertSame('99990001'.'0012345678', $account->key(), 'entidad + oficina + los 10 dígitos de cuenta, como getBankKey()');
        $this->assertSame('2091-03-05', $account->from->format('Y-m-d'));
        $this->assertSame('2091-03-12', $account->to->format('Y-m-d'));
        $this->assertSame(150000, $account->openingCents);
        $this->assertSame(150000 - 4550 + 6000, $account->closingCents);
        $this->assertCount(3, $account->movements);
        $light = $account->movements[0];
        $this->assertSame(-4550, $light->amountCents);
        $this->assertSame('2091-03-05', $light->valueDate->format('Y-m-d'));
        $this->assertSame(['RECIBO LUZ', 'DATO EXTRA'], $light->texts);
        $this->assertSame('', $light->document);
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        yield 'Excel 97' => ['Xls'];
        yield 'Excel 2007' => ['Xlsx'];
    }

    /**
     * Como la de Fiare: del más nuevo al más antiguo y sin IBAN. Se devuelven del más
     * antiguo al más nuevo y la cuenta queda por elegir.
     */
    public function testHojaDelMasNuevoAlMasAntiguoSinIban(): void
    {
        $raw = BankSheet::fiareLike(76.02, [
            ['2091-01-01', -25.00, 'DEVOLUCION RECIBO'],
            ['2091-01-01', -1.21, 'COMISION DEVOLUCION'],
            ['2091-01-02', 968.00, 'TRF. ASOCIACION INVENTADA'],
        ]);

        $account = (new BankSpreadsheetReader())->read($raw)->accounts[0];

        $this->assertSame(Norma43Account::UNKNOWN_KEY, $account->key());
        $this->assertSame('cuenta sin IBAN en el fichero', $account->label());
        $this->assertSame(7602, $account->openingCents, 'saldo de la fila más antigua menos su importe');
        $this->assertSame(7602 - 2500 - 121 + 96800, $account->closingCents);
        $this->assertSame([-2500, -121, 96800], array_map(static fn ($m) => $m->amountCents, $account->movements));
        $this->assertSame(['DEVOLUCION RECIBO'], $account->movements[0]->texts);
    }

    /** Un saldo que no es el anterior más el importe: falta o sobra un movimiento. */
    public function testCadenaDeSaldosRotaSeRechaza(): void
    {
        $raw = BankSheet::build([], ['Fecha', 'Concepto', 'Importe', 'Saldo'], [
            ['2091-03-01', 'UNO', 10.00, 110.00],
            ['2091-03-02', 'DOS', 10.00, 120.00],
            ['2091-03-03', 'TRES', 10.00, 135.00],
        ], [0]);

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('Los saldos no cuadran en la fila');
        (new BankSpreadsheetReader())->read($raw);
    }

    /** Las filas vacías (al final, o entre bloques) no son movimientos. */
    public function testFilasVaciasSeSaltan(): void
    {
        $raw = BankSheet::build([['Extracto'], []], ['Fecha', 'Concepto', 'Importe', 'Saldo'], [
            ['2091-03-01', 'UNO', 10.00, 110.00],
            [null, null, null, null],
            ['2091-03-02', 'DOS', -5.00, 105.00],
        ], [0]);

        $account = (new BankSpreadsheetReader())->read($raw)->accounts[0];

        $this->assertCount(2, $account->movements);
        $this->assertSame(10000, $account->openingCents);
        $this->assertSame(10500, $account->closingCents);
    }

    /** Sin columnas Fecha, Importe y Saldo no se sabe leer la hoja. */
    public function testSinCabeceraSeRechaza(): void
    {
        $raw = BankSheet::build([], ['Día', 'Cantidad'], [['2091-03-01', 10.00]], [0]);

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('No encuentro la fila de cabecera');
        (new BankSpreadsheetReader())->read($raw);
    }

    /** Un importe escrito como texto no se adivina. */
    public function testImporteQueNoEsNumeroSeRechaza(): void
    {
        $raw = BankSheet::build([], ['Fecha', 'Concepto', 'Importe', 'Saldo'], [['2091-03-01', 'UNO', 'diez', 110.00]], [0]);

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('Fila 2: el importe «diez» no es un número.');
        (new BankSpreadsheetReader())->read($raw);
    }

    /** Cabecera sin movimientos debajo: no es un extracto. */
    public function testSinMovimientosSeRechaza(): void
    {
        $raw = BankSheet::build([], ['Fecha', 'Concepto', 'Importe', 'Saldo'], [], [0]);

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('no tiene movimientos');
        (new BankSpreadsheetReader())->read($raw);
    }

    /**
     * El lector común decide por el contenido: el texto Norma 43 va a su lector y la
     * hoja al suyo, y el mismo movimiento tiene la misma identidad venga de donde venga
     * (si no, subir octubre en la hoja y luego en Norma 43 lo duplicaría).
     */
    public function testElLectorComunEligePorContenidoYLaIdentidadNoDependeDelFormato(): void
    {
        $reader = new BankStatementReader(new Norma43Reader(), new BankSpreadsheetReader());
        $n43 = N43::file(N43::account('9999', '0001', '0012345678', '910301', '910331', 150000, [['910305', -4550, ['RECIBO LUZ']]]));
        $sheet = BankSheet::caixaLike('ES1299990001110012345678', 1500.00, [['2091-03-05', -45.50, 'RECIBO LUZ']]);

        $this->assertFalse(BankSpreadsheetReader::looksLikeSpreadsheet($n43));
        $fromN43 = $reader->read($n43)->accounts[0];
        $fromSheet = $reader->read($sheet)->accounts[0];

        $this->assertSame($fromN43->key(), $fromSheet->key());
        $this->assertSame($fromN43->movements[0]->identity(), $fromSheet->movements[0]->identity());
    }
}
