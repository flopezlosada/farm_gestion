<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Service\Accounting\Remittance\RemittancePeriodText;
use PHPUnit\Framework\TestCase;

/**
 * Los textos de una remesa copiada pasan al mes nuevo: el nombre y las referencias
 * de adeudo, escritos como se escriben a mano.
 */
class RemittancePeriodTextTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function rewrites(): iterable
    {
        yield 'nombre en mayúsculas' => ['Recibos AGOSTO 2026', '2026-08', '2026-09', 'Recibos SEPTIEMBRE 2026'];
        yield 'nombre con inicial' => ['Cuota Agosto 2026', '2026-08', '2026-09', 'Cuota Septiembre 2026'];
        yield 'nombre en minúsculas' => ['recibo agosto', '2026-08', '2026-09', 'recibo septiembre'];
        yield 'cambio de año' => ['Recibos  DICIEMBRE 2025', '2025-12', '2026-01', 'Recibos  ENERO 2026'];
        yield 'aaaamm' => ['REC202608-17', '2026-08', '2026-09', 'REC202609-17'];
        yield 'aaaa-mm' => ['CSA 2026-08 / 17', '2026-08', '2026-09', 'CSA 2026-09 / 17'];
        yield 'mm/aaaa' => ['CUOTA 08/2026', '2026-08', '2026-09', 'CUOTA 09/2026'];
        yield 'mm.aaaa con cambio de año' => ['CUOTA 12.2025', '2025-12', '2026-01', 'CUOTA 01.2026'];
        yield 'sólo el año, semestral' => ['1ª Cuota SEMESTRAL 2025', '2025-10', '2026-04', '1ª Cuota SEMESTRAL 2026'];
        yield 'el número del mandato no es una fecha' => ['CUOTA MENSUAL 200', '2026-08', '2026-09', 'CUOTA MENSUAL 200'];
        yield 'otro mes no se toca' => ['Recibos JULIO 2026', '2026-08', '2026-09', 'Recibos JULIO 2026'];
        yield 'mismo mes' => ['Recibos AGOSTO 2026', '2026-08', '2026-08', 'Recibos AGOSTO 2026'];
        yield 'mes dentro de otra palabra' => ['MAYORAL MAYO 2026', '2026-05', '2026-06', 'MAYORAL JUNIO 2026'];
    }

    /** @dataProvider rewrites */
    public function testPasaElTextoAlMesNuevo(string $text, string $from, string $to, string $expected): void
    {
        $this->assertSame($expected, RemittancePeriodText::rewrite($text, new \DateTimeImmutable($from . '-01'), new \DateTimeImmutable($to . '-01')));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function fileNames(): iterable
    {
        yield 'con número delante' => ['08. Recibos AGOSTO 2026.xlsx', '2026-08-01'];
        yield 'doble espacio' => ['10. Recibos  OCTUBRE 2025.xlsx', '2025-10-01'];
        yield 'en minúsculas' => ['recibos marzo-2026.ods', '2026-03-01'];
        yield 'sin mes' => ['CUOTA ANUAL 2025.xlsx', null];
        yield 'sin año' => ['Recibos AGOSTO.xlsx', null];
    }

    /** @dataProvider fileNames */
    public function testSacaElMesDelNombreDelFichero(string $name, ?string $expected): void
    {
        $this->assertSame($expected, RemittancePeriodText::guessPeriod($name)?->format('Y-m-d'));
    }
}
