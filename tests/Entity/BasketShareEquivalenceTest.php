<?php

namespace App\Tests\Entity;

use App\Entity\BasketShare;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la equivalencia mensual en cestas completas
 * (getCompleteBasketEquivalence): semanal 1, quincenal/semanal-compartida 0,5,
 * mensual/quincenal-compartida 0,25, mensual compartida 0,13, solo huevos 0.
 *
 * Regresión: la columna es decimal y Doctrine la hidrata como string ("0.50");
 * el getter histórico declaraba `?int` y PHP coercionaba a 0, así que todas
 * las modalidades no semanales contaban 0 (lo sufrían el total del listado de
 * cestas activas y PartnerRepository::findAmountBasketsByMonth).
 *
 * Es la pareja de BasketShareWeightTest: aquélla es el peso FÍSICO del día de
 * entrega; ésta, el valor amortizado al mes (lo que cuenta el cobro).
 */
class BasketShareEquivalenceTest extends TestCase
{
    /**
     * @dataProvider equivalencias
     */
    public function testEquivalenciaDecimalNoSeTruncaAEntero(string $almacenado, float $esperado): void
    {
        $share = new BasketShare();
        $share->setCompleteBasketEquivalence($almacenado);

        $this->assertSame($esperado, $share->getCompleteBasketEquivalence());
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function equivalencias(): array
    {
        return [
            'semanal "1.00" → 1.0'              => ['1.00', 1.0],
            'quincenal "0.50" → 0.5, no 0'      => ['0.50', 0.5],
            'mensual "0.25" → 0.25, no 0'       => ['0.25', 0.25],
            'mensual compartida "0.13" → 0.13'  => ['0.13', 0.13],
            'solo huevos "0.00" → 0.0'          => ['0.00', 0.0],
        ];
    }
}
