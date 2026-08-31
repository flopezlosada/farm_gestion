<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Service\ConsumerGroup\ConsumerGroupStats;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la parte pura de la analítica: la tasa de confirmación (pedidos
 * confirmados sobre los decididos = confirmados + cancelados). La agregación SQL se
 * valida con datos reales.
 */
class ConsumerGroupStatsTest extends TestCase
{
    public function testTasaNullSiNoHayNadaDecidido(): void
    {
        self::assertNull(ConsumerGroupStats::confirmationRate(0, 0));
    }

    public function testTasaConfirmadosSobreDecididos(): void
    {
        // 3 confirmados y 1 cancelado → 3/4 = 0,75.
        self::assertSame(0.75, ConsumerGroupStats::confirmationRate(3, 1));
    }

    public function testTasaCienPorCienSiNadaSeCancela(): void
    {
        self::assertSame(1.0, ConsumerGroupStats::confirmationRate(4, 0));
    }
}
