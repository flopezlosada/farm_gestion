<?php

namespace App\Tests\Service\Accounting\Norma43;

use App\Service\Accounting\Norma43\Norma43Exception;
use App\Service\Accounting\Norma43\Norma43Reader;
use PHPUnit\Framework\TestCase;

/**
 * Lectura y validación de extractos Norma 43 sintéticos (cifras inventadas).
 */
class Norma43ReaderTest extends TestCase
{
    /**
     * Dos cuentas en un fichero: la primera con complementarios (23) y un registro de
     * divisa (24), la segunda en saldo deudor (negativo).
     */
    public function testDosCuentasConComplementariosYDivisa(): void
    {
        $lines = [
            N43::header('2100', '0418', '0200051332', '261001', '261031', 150000),
            N43::movement('261003', -4550, '0000000001', '000000000123', 'REF2'),
            N43::complement(1, 'RECIBO LUZ OCTUBRE', 'COMERCIALIZADORA'),
            N43::complement(2, 'CONTRATO 99'),
            N43::movement('261015', 2000, '0000000002'),
            N43::equivalence(),
            N43::close('2100', '0418', '0200051332', 1, 4550, 1, 2000, 147450),
            ...N43::account('3025', '0001', '1234567890', '261001', '261031', -20000, [['261020', -1000]]),
        ];

        $file = (new Norma43Reader())->read(N43::file($lines));

        $this->assertSame([], $file->warnings);
        $this->assertCount(2, $file->accounts);
        [$first, $second] = $file->accounts;
        $this->assertSame('21000418'.'0200051332', $first->key());
        $this->assertSame('2026-10-01', $first->from->format('Y-m-d'));
        $this->assertSame('2026-10-31', $first->to->format('Y-m-d'));
        $this->assertSame(150000, $first->openingCents);
        $this->assertSame(147450, $first->closingCents);
        $this->assertSame('ASOCIACION DE PRUEBA', $first->holder);
        $this->assertCount(2, $first->movements);
        $out = $first->movements[0];
        $this->assertSame(-4550, $out->amountCents);
        $this->assertSame('2026-10-03', $out->operationDate->format('Y-m-d'));
        $this->assertSame('0000000001', $out->document);
        $this->assertSame('000000000123', $out->reference1);
        $this->assertSame('REF2', $out->reference2);
        $this->assertSame(['RECIBO LUZ OCTUBRE COMERCIALIZADORA', 'CONTRATO 99'], $out->texts);
        $this->assertSame(2000, $first->movements[1]->amountCents);
        $this->assertSame([], $first->movements[1]->texts);

        $this->assertSame(-20000, $second->openingCents);
        $this->assertSame(-21000, $second->closingCents);
    }

    /** Saltos CRLF, blancos finales recortados y sin el 88: se lee, con un aviso. */
    public function testToleraCrlfBlancosRecortadosYFaltaDelFin(): void
    {
        $lines = array_map('rtrim', N43::account('2100', '0418', '0200051332', '261001', '261031', 100, [['261002', 50, ['TEXTO CORTO']]]));

        $file = (new Norma43Reader())->read(N43::file($lines, withEnd: false, eol: "\r\n")."\r\n");

        $this->assertCount(1, $file->accounts);
        $this->assertSame(150, $file->accounts[0]->closingCents);
        $this->assertSame(['TEXTO CORTO'], $file->accounts[0]->movements[0]->texts);
        $this->assertCount(1, $file->warnings);
        $this->assertStringContainsString('registro de fin (88)', $file->warnings[0]);
    }

    /** Un fichero de MS-DOS (CP850) con la Ñ como único byte alto. */
    public function testCp850ConEnie(): void
    {
        $raw = N43::file(N43::account('2100', '0418', '0200051332', '261001', '261031', 0, [['261002', 1000, ["CUOTA CA\xA5ADA"]]]));

        $file = (new Norma43Reader())->read($raw);

        $this->assertSame(['CUOTA CAÑADA'], $file->accounts[0]->movements[0]->texts);
    }

    /** Windows-1252 (otra Ñ, otro byte) y UTF-8 se leen bien. */
    public function testWindows1252YUtf8(): void
    {
        $reader = new Norma43Reader();
        $cp1252 = N43::file(N43::account('2100', '0418', '0200051332', '261001', '261031', 0, [['261002', 1000, ["PE\xD1A \xC1GIL"]]]));
        $utf8 = N43::file(N43::account('2100', '0418', '0200051332', '261001', '261031', 0, [['261002', 1000, ['PEÑA ÁGIL']]]));

        $this->assertSame(['PEÑA ÁGIL'], $reader->read($cp1252)->accounts[0]->movements[0]->texts);
        $this->assertSame(['PEÑA ÁGIL'], $reader->read($utf8)->accounts[0]->movements[0]->texts);
    }

    /**
     * Cada validación que rechaza el fichero entero, con un mensaje que lo explica.
     *
     * @dataProvider rejected
     */
    public function testRechaza(string $raw, string $message): void
    {
        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage($message);

        (new Norma43Reader())->read($raw);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function rejected(): iterable
    {
        $h = N43::header('2100', '0418', '0200051332', '261001', '261031', 10000);
        $m = N43::movement('261005', -1000);
        $c = N43::close('2100', '0418', '0200051332', 1, 1000, 0, 0, 9000);

        yield 'movimiento antes de la cabecera' => [N43::file([$m, $h, $c]), 'tiene que ir dentro de una cuenta'];
        yield 'complementario sin movimiento' => [N43::file([$h, N43::complement(1, 'X'), $c]), 'detrás de un movimiento'];
        yield 'el 33 es de otra cuenta' => [N43::file([$h, $m, N43::close('2100', '0418', '0200059999', 1, 1000, 0, 0, 9000)]), 'es de otra cuenta'];
        yield 'nº de apuntes del debe' => [N43::file([$h, $m, N43::close('2100', '0418', '0200051332', 2, 1000, 0, 0, 9000)]), 'apuntes que salen'];
        yield 'suma del haber' => [N43::file([$h, $m, N43::close('2100', '0418', '0200051332', 1, 1000, 0, 500, 9500)]), 'total de lo que entra'];
        yield 'saldo final' => [N43::file([$h, $m, N43::close('2100', '0418', '0200051332', 1, 1000, 0, 0, 9001)]), 'el inicial (100,00 €) con sus movimientos da 90,00 €'];
        yield 'nº de registros del 88' => [implode("\n", [$h, $m, $c, N43::end(4)]), 'dice que hay 4 registros y hay 3'];
        yield 'fecha fuera del extracto' => [N43::file([$h, N43::movement('261105', -1000), $c]), 'el extracto va del 01/10/2026 al 31/10/2026'];
        yield 'fecha que no existe' => [N43::file([N43::header('2100', '0418', '0200051332', '260201', '260230', 0), N43::close('2100', '0418', '0200051332', 0, 0, 0, 0, 0)]), 'no es una fecha'];
        yield 'otra divisa' => [N43::file([N43::header('2100', '0418', '0200051332', '261001', '261031', 0, '840'), N43::close('2100', '0418', '0200051332', 0, 0, 0, 0, 0)]), 'sólo lleva euros'];
        yield 'línea de más de 80' => [N43::file([$h.'X', $m, $c]), 'mide 81 caracteres'];
        yield 'registro desconocido' => [N43::file([$h, '44'.str_repeat('0', 78), $c]), 'el registro «44» no existe'];
        yield 'seis complementarios' => [N43::file([$h, $m, ...array_map(static fn (int $i) => N43::complement($i, 'T'), [1, 2, 3, 4, 5]), N43::complement(6, 'T'), $c]), 'como mucho 5'];
        yield 'complementarios salteados' => [N43::file([$h, $m, N43::complement(2, 'T'), $c]), 'tocaba el 01'];
        yield 'complementario después del 24' => [N43::file([$h, $m, N43::equivalence(), N43::complement(1, 'T'), $c]), 'antes del de divisa'];
        yield 'cuenta sin cerrar' => [implode("\n", [$h, $m]), 'acaba sin el registro final (33)'];
        yield 'línea vacía en medio' => [implode("\n", [$h, '', $m, $c]), 'Línea 2: está vacía'];
        yield 'nada después del 88' => [implode("\n", [$h, $m, $c, N43::end(3), $h]), 'después del de fin de fichero'];
        yield 'clave debe/haber' => [N43::file([$h, substr_replace($m, '3', 27, 1), $c]), 'clave debe/haber'];
        yield 'fichero que no es Norma 43' => ["fecha;concepto;importe\n01/10/2026;algo;10\n", 'Norma 43'];
    }
}
