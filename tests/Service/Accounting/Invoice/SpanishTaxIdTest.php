<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Service\Accounting\Invoice\SpanishTaxId;
use PHPUnit\Framework\TestCase;

/**
 * El control de NIF, NIE y CIF: los identificadores reales cuadran y una cifra
 * cambiada, que es lo que deja una errata o una mala lectura, no.
 */
class SpanishTaxIdTest extends TestCase
{
    /**
     * Identificadores bien escritos de cada forma.
     *
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'asociación (G: cifra o letra, aquí cifra)' => ['G87587580'];
        yield 'sociedad limitada (B: siempre cifra)' => ['B45351178'];
        yield 'sociedad anónima (A: siempre cifra)' => ['A78923125'];
        yield 'NIF de autónomo' => ['05427289W'];
        yield 'NIE' => ['X1234567L'];
        yield 'organismo público (Q: siempre letra)' => ['Q2816003D'];
        yield 'NIF especial (K)' => ['K1234567L'];
    }

    /**
     * Los bien escritos cuadran.
     *
     * @dataProvider valid
     */
    public function testUnIdentificadorBienEscritoCuadra(string $taxId): void
    {
        $this->assertTrue(SpanishTaxId::isValid($taxId));
    }

    /**
     * Erratas típicas y cosas que no son un identificador.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'CIF con el control cambiado' => ['G87587581'];
        yield 'CIF con una cifra del medio cambiada' => ['B45351278'];
        yield 'NIF con la letra que no es' => ['05427289X'];
        yield 'NIE con la letra que no es' => ['X1234567A'];
        yield 'sociedad limitada con letra de control' => ['B4535117H'];
        yield 'organismo público con cifra de control' => ['Q28160034'];
        yield 'letra inicial que no existe' => ['I87587580'];
        yield 'le falta una cifra' => ['B4535117'];
        yield 'vacío' => [''];
    }

    /**
     * Los mal escritos no cuadran.
     *
     * @dataProvider invalid
     */
    public function testUnIdentificadorMalEscritoNoCuadra(string $taxId): void
    {
        $this->assertFalse(SpanishTaxId::isValid($taxId));
    }
}
