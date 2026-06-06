<?php

namespace App\Tests\Entity;

use App\Entity\BasketShare;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del peso de cesta física por modalidad (getDeliveredBasketWeight).
 * Es la regla que el composer estampa en el ítem de verdura y que el reparto
 * suma: compartidas (4/6/7) = ½, solo-huevos (5) = 0, el resto = 1.
 *
 * OJO: este peso NO es complete_basket_equivalence (que amortiza el VALOR a lo
 * largo del mes: quincenal 0,5, mensual 0,25). Aquí mensual y quincenal pesan 1
 * el día que reparten — un test explícito para que nadie las confunda.
 */
class BasketShareWeightTest extends TestCase
{
    /**
     * @dataProvider pesosPorModalidad
     */
    public function testPesoFisicoPorModalidad(int $id, float $esperado): void
    {
        $share = new BasketShare();
        $share->setId($id);

        $this->assertSame($esperado, $share->getDeliveredBasketWeight());
    }

    /**
     * @return array<string, array{int, float}>
     */
    public static function pesosPorModalidad(): array
    {
        return [
            'semanal (1) pesa 1'              => [1, 1.0],
            'quincenal (2) pesa 1, no 0,5'    => [2, 1.0],
            'mensual (3) pesa 1, no 0,25'     => [3, 1.0],
            'semanal compartida (4) pesa ½'   => [4, 0.5],
            'solo huevos (5) pesa 0'          => [5, 0.0],
            'quincenal compartida (6) pesa ½' => [6, 0.5],
            'mensual compartida (7) pesa ½'   => [7, 0.5],
        ];
    }
}
