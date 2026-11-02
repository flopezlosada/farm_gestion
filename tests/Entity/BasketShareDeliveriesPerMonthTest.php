<?php

namespace App\Tests\Entity;

use App\Entity\BasketShare;
use PHPUnit\Framework\TestCase;

/**
 * Cuántas cestas trae cada modalidad en un mes normal, para cobrar por cestas el mes
 * en que empieza a medias: las quincenales dos, las mensuales una (compartidas
 * incluidas) y las de cada semana ninguna cifra fija, porque se cuentan por semanas.
 */
class BasketShareDeliveriesPerMonthTest extends TestCase
{
    /**
     * @dataProvider cestasPorModalidad
     */
    public function testCestasAlMesPorModalidad(int $id, ?int $esperado): void
    {
        $share = new BasketShare();
        $share->setId($id);

        $this->assertSame($esperado, $share->getDeliveriesPerMonth());
    }

    /**
     * @return array<string, array{int, ?int}>
     */
    public static function cestasPorModalidad(): array
    {
        return [
            'semanal: por semanas'              => [BasketShare::ID_WEEKLY, null],
            'semanal compartida: por semanas'   => [BasketShare::ID_WEEKLY_SHARED, null],
            'solo huevos: por semanas'          => [BasketShare::ID_ONLY_EGG, null],
            'quincenal: dos'                    => [BasketShare::ID_BIWEEKLY, 2],
            'quincenal compartida: dos'         => [BasketShare::ID_BIWEEKLY_SHARED, 2],
            'mensual: una'                      => [BasketShare::ID_MONTHLY, 1],
            'mensual compartida: una'           => [BasketShare::ID_MONTHLY_SHARED, 1],
        ];
    }
}
