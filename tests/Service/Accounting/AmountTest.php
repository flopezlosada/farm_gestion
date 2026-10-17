<?php

namespace App\Tests\Service\Accounting;

use App\Service\Accounting\Amount;
use PHPUnit\Framework\TestCase;

/**
 * Importes tecleados en la rejilla del presupuesto o leídos del Excel de recibos.
 */
class AmountTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function amounts(): iterable
    {
        yield 'coma decimal' => ['43,5', '43.50'];
        yield 'punto decimal del teclado numérico' => ['12.50', '12.50'];
        yield 'punto decimal con una cifra' => ['12.5', '12.50'];
        yield 'miles con punto' => ['1.200', '1200.00'];
        yield 'miles y decimales' => ['1.200,00', '1200.00'];
        yield 'millones' => ['1.234.567', '1234567.00'];
        yield 'negativo con miles (financiación)' => ['-1.200', '-1200.00'];
        yield 'negativo con coma' => ['-200,00', '-200.00'];
        yield 'con euro y espacios' => [' 7.880,00 € ', '7880.00'];
        yield 'espacio duro de Excel' => ["1\u{a0}200,50", '1200.50'];
        yield 'número de Excel' => [43.5, '43.50'];
        yield 'entero' => [65, '65.00'];
        yield 'vacío' => ['', null];
        yield 'texto' => ['abc', null];
        yield 'null' => [null, null];
    }

    /** @dataProvider amounts */
    public function testParse(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, Amount::parse($raw));
    }
}
