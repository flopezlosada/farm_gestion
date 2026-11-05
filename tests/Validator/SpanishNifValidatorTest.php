<?php

namespace App\Tests\Validator;

use App\Validator\SpanishNifValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La letra de control del DNI y del NIE, con números cuya letra se ha calculado a
 * mano (resto de dividir entre 23).
 */
class SpanishNifValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function nifs(): iterable
    {
        yield 'DNI bien' => ['12345678Z', true];
        yield 'DNI con ceros delante' => ['00000000T', true];
        yield 'NIE con X' => ['X1234567L', true];
        yield 'NIE con Y' => ['Y1234567X', true];
        yield 'NIE con Z' => ['Z1234567R', true];
        yield 'DNI con la letra cambiada' => ['12345678A', false];
        yield 'DNI con un dígito cambiado' => ['12345679Z', false];
        yield 'NIE con la letra cambiada' => ['X1234567T', false];
        yield 'le falta un dígito' => ['1234567Z', false];
        yield 'sin letra' => ['12345678', false];
        yield 'NIE con letra inicial que no existe' => ['W1234567L', false];
        yield 'sin normalizar (minúscula)' => ['12345678z', false];
    }

    /**
     * @param string $nif
     * @param bool   $expected
     * @return void
     */
    #[DataProvider('nifs')]
    public function testLaLetraDeControl(string $nif, bool $expected): void
    {
        $this->assertSame($expected, SpanishNifValidator::isValid($nif));
    }
}
