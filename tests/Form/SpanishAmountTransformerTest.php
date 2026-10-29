<?php

namespace App\Tests\Form;

use App\Form\DataTransformer\SpanishAmountTransformer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * La lectura de un importe tal como se escribe en España, y cómo se enseña de vuelta.
 */
class SpanishAmountTransformerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function written(): iterable
    {
        yield 'miles con punto y coma decimal' => ['41.496,30', '41496.30'];
        yield 'coma decimal sin miles' => ['41496,30', '41496.30'];
        yield 'punto decimal' => ['41496.30', '41496.30'];
        yield 'entero' => ['41496', '41496.00'];
        yield 'punto de miles sin decimales' => ['1.500', '1500.00'];
        yield 'varios grupos de miles' => ['1.234.567,8', '1234567.80'];
        yield 'punto que no es de miles' => ['1.50', '1.50'];
        yield 'negativo' => ['-120,5', '-120.50'];
        yield 'con espacios y símbolo' => [' 12.225,00 € ', '12225.00'];
        yield 'cero' => ['0', '0.00'];
    }

    /** @dataProvider written */
    public function testLeeLoEscrito(string $written, string $model): void
    {
        $this->assertSame($model, (new SpanishAmountTransformer())->reverseTransform($written));
    }

    public function testLaCasillaVaciaEsNull(): void
    {
        $transformer = new SpanishAmountTransformer();

        $this->assertNull($transformer->reverseTransform(''));
        $this->assertNull($transformer->reverseTransform('  '));
        $this->assertNull($transformer->reverseTransform(null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAmounts(): iterable
    {
        yield 'letras' => ['doce'];
        yield 'dos comas' => ['1,2,3'];
        yield 'coma antes del punto' => ['41,496.30'];
        yield 'grupo de miles mal' => ['41.49,30'];
    }

    /** @dataProvider notAmounts */
    public function testRechazaLoQueNoEsUnImporte(string $written): void
    {
        $this->expectException(TransformationFailedException::class);

        (new SpanishAmountTransformer())->reverseTransform($written);
    }

    public function testEnsenaElImporteConComaYSinMiles(): void
    {
        $transformer = new SpanishAmountTransformer();

        $this->assertSame('12225,00', $transformer->transform('12225.00'));
        $this->assertSame('-0,50', $transformer->transform('-0.5'));
        $this->assertSame('', $transformer->transform(null));
    }
}
