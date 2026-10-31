<?php

namespace App\Tests\Service\Accounting\CashCount;

use App\Service\Accounting\CashCount\CashCountMessage;
use App\Service\Accounting\CashCount\CashCountRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se lee un arqueo escrito al bot: el importe sin adivinar (lo dudoso se pide
 * otra vez) y la fecha, que sin año es la más reciente que no sea futura.
 */
class CashCountMessageTest extends TestCase
{
    private const TODAY = '2026-10-31';

    /** @return iterable<string, array{string, int}> */
    public static function amounts(): iterable
    {
        yield 'coma decimal' => ['400,56', 40056];
        yield 'punto decimal del teclado numérico' => ['400.56', 40056];
        yield 'sin decimales' => ['400', 40000];
        yield 'un decimal' => ['400,5', 40050];
        yield 'un decimal con punto' => ['400.5', 40050];
        yield 'miles con punto y coma decimal' => ['1.234,56', 123456];
        yield 'miles sin separar' => ['1234,56', 123456];
        yield 'caja vacía' => ['0', 0];
        yield 'cero con decimales' => ['0,00', 0];
    }

    #[DataProvider('amounts')]
    public function testImportesQueSeEntienden(string $raw, int $cents): void
    {
        $this->assertSame($cents, CashCountMessage::amountCents($raw));
        $this->assertSame($cents, $this->parse('caja huerta ' . $raw)->countedCents);
    }

    /** Con el símbolo del euro, pegado o separado, y con espacios de no separación. */
    public function testElEuroYLosEspaciosNoEstorban(): void
    {
        $this->assertSame(40056, $this->parse('caja huerta 400,56 €')->countedCents);
        $this->assertSame(40056, $this->parse('caja huerta 400,56€')->countedCents);
        $this->assertSame(40056, $this->parse("Caja\u{00A0}huerta  400,56 euros")->countedCents);
        $this->assertSame('huerta', $this->parse('caja huerta 400,56 €')->accountName);
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousAmounts(): iterable
    {
        yield 'coma con tres cifras: ¿mil o uno?' => ['1,234'];
        yield 'punto con tres cifras: ¿miles o decimales?' => ['400.560'];
        yield 'punto de miles sin decimales' => ['1.234'];
        yield 'tres decimales' => ['400,567'];
        yield 'dos separadores decimales' => ['400,5,6'];
    }

    #[DataProvider('ambiguousAmounts')]
    public function testImportesDudososSePidenConComaDecimal(string $raw): void
    {
        $this->expectException(CashCountRejected::class);
        $this->expectExceptionMessage('coma decimal');

        $this->parse('caja huerta ' . $raw);
    }

    public function testUnImporteNegativoSeRechaza(): void
    {
        $this->expectException(CashCountRejected::class);
        $this->expectExceptionMessage('no puede ser negativo');

        $this->parse('caja huerta -20');
    }

    /** Sin fecha es de hoy; con «30/10», de este año; con año, ese. */
    public function testLaFecha(): void
    {
        $this->assertSame(self::TODAY, $this->parse('caja huerta 400,56')->date->format('Y-m-d'));
        $this->assertSame('2026-10-30', $this->parse('caja huerta 400,56 30/10')->date->format('Y-m-d'));
        $this->assertSame('2026-10-31', $this->parse('caja huerta 400,56 31/10')->date->format('Y-m-d'), 'hoy vale');
        $this->assertSame('2025-09-30', $this->parse('caja huerta 400,56 30/9/25')->date->format('Y-m-d'));
        $this->assertSame('2025-09-30', $this->parse('caja huerta 400,56 30/09/2025')->date->format('Y-m-d'));
    }

    /** El 2 de enero, «31/12» es el arqueo de diciembre que se manda con un día de retraso. */
    public function testSinAnoEsLaFechaMasRecienteQueNoSeaFutura(): void
    {
        $count = CashCountMessage::parse('caja huerta 400 31/12', new \DateTimeImmutable('2027-01-02'));

        $this->assertSame('2026-12-31', $count->date->format('Y-m-d'));
    }

    public function testUnaFechaConAnoFuturaSeRechaza(): void
    {
        $this->expectException(CashCountRejected::class);
        $this->expectExceptionMessage('todavía no ha llegado');

        $this->parse('caja huerta 400 1/11/2026');
    }

    public function testUnaFechaQueNoExisteSeRechaza(): void
    {
        $this->expectException(CashCountRejected::class);
        $this->expectExceptionMessage('no existe');

        $this->parse('caja huerta 400 31/02/2026');
    }

    /** Lo que no tiene forma de arqueo no es un arqueo: el bot lo deja para los demás apartados. */
    public function testQueTieneFormaDeArqueo(): void
    {
        $this->assertTrue(CashCountMessage::looksLikeOne('caja huerta 400,56'));
        $this->assertTrue(CashCountMessage::looksLikeOne('Caja de la huerta 400,56 € 30/10'));
        $this->assertTrue(CashCountMessage::looksLikeOne('caja huerta -20'), 'tiene la forma; se rechaza al leerlo');
        $this->assertTrue(CashCountMessage::looksLikeOne('caja huerta 2 400'), 'el nombre puede llevar números');
        $this->assertSame('huerta 2', $this->parse('caja huerta 2 400')->accountName);

        $this->assertFalse(CashCountMessage::looksLikeOne('he pasado 50 € de la caja del local a la de la huerta'));
        $this->assertFalse(CashCountMessage::looksLikeOne('caja huerta'));
        $this->assertFalse(CashCountMessage::looksLikeOne('caja 400'));
        $this->assertFalse(CashCountMessage::looksLikeOne('/caja huerta 400'));
        $this->assertFalse(CashCountMessage::looksLikeOne(''));
    }

    private function parse(string $text): CashCountMessage
    {
        return CashCountMessage::parse($text, new \DateTimeImmutable(self::TODAY . ' 18:30'));
    }
}
