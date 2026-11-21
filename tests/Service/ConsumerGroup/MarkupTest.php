<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\MarkupType;
use App\Service\ConsumerGroup\Markup;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del incremento de gestión: aritmética en céntimos y valores inválidos.
 */
class MarkupTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function casos(): iterable
    {
        yield 'porcentaje exacto' => ['percent', '15', '10.00', '11.50'];
        yield 'porcentaje redondea hacia abajo' => ['percent', '12.5', '1.15', '1.29'];
        yield 'porcentaje redondea la mitad hacia arriba' => ['percent', '50', '0.30', '0.45'];
        yield 'euros por unidad' => ['amount', '0.20', '2.00', '2.20'];
        yield 'cero por ciento' => ['percent', '0', '3.10', '3.10'];
        yield 'cero euros' => ['amount', '0', '3.10', '3.10'];
        yield 'precio de la BBDD con dos decimales' => ['percent', '10.00', '4.50', '4.95'];
    }

    /**
     * @dataProvider casos
     */
    public function testAplicaElIncrementoAlPrecioDelProductor(string $type, string $value, string $producer, string $expected): void
    {
        $markup = Markup::fromStored(MarkupType::from($type), $value);

        self::assertNotNull($markup);
        self::assertSame($expected, $markup->applyTo($producer));
    }

    public function testSinTipoOSinValorNoHayIncremento(): void
    {
        self::assertNull(Markup::fromStored(null, '10.00'));
        self::assertNull(Markup::fromStored(MarkupType::Percent, null));
        self::assertNull(Markup::fromStored(null, null));
    }

    public function testUnValorNegativoNoSePuedeConstruir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Markup::fromStored(MarkupType::Amount, '-0.10');
    }

    public function testUnValorQueNoCabeEnLaColumnaSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Markup::fromStored(MarkupType::Percent, '1000000');
    }

    public function testUnPrecioFinalQueNoCabeSeRechaza(): void
    {
        $markup = Markup::fromStored(MarkupType::Percent, '999999.99');

        $this->expectException(\InvalidArgumentException::class);
        $markup->applyTo('5000.00');
    }
}
