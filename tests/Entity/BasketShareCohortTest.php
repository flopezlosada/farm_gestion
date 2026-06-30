<?php

namespace App\Tests\Entity;

use App\Entity\BasketShare;
use PHPUnit\Framework\TestCase;

/**
 * Cubre {@see BasketShare::usesBiweeklyCohort}: el criterio de qué modalidades
 * usan el turno A/B (delivery_group). Regresión del bug 2026-06-30 (Ana Villa /
 * Jose Carrascosa): la Quincenal compartida (6) perdía el turno al cambiar de
 * modalidad porque el server sólo lo conservaba para la Quincenal (2), y sin
 * turno una quincenal no se materializa en ningún listado.
 */
class BasketShareCohortTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function modalidades(): iterable
    {
        yield 'Semanal (1) no usa turno' => [1, false];
        yield 'Quincenal (2) usa turno' => [2, true];
        yield 'Mensual (3) no usa turno' => [3, false];
        yield 'Semanal compartida (4) no usa turno' => [4, false];
        yield 'Solo huevos (5) no usa turno' => [5, false];
        yield 'Quincenal compartida (6) usa turno' => [6, true];
        yield 'Mensual compartida (7) no usa turno' => [7, false];
    }

    /**
     * @dataProvider modalidades
     */
    public function testUsesBiweeklyCohort(int $id, bool $expected): void
    {
        $this->assertSame($expected, $this->basketShare($id)->usesBiweeklyCohort());
    }

    public function testLasDosQuincenalesEstanEnIdsBiweekly(): void
    {
        // La compartida (6) es quincenal a efectos de reparto, igual que la (2).
        $this->assertContains(2, BasketShare::IDS_BIWEEKLY);
        $this->assertContains(6, BasketShare::IDS_BIWEEKLY);
    }

    private function basketShare(int $id): BasketShare
    {
        $basketShare = new BasketShare();
        $ref = new \ReflectionProperty(BasketShare::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($basketShare, $id);

        return $basketShare;
    }
}
