<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\WorkingDayCounter;
use PHPUnit\Framework\TestCase;

/**
 * Conteo de días laborables para el saldo de vacaciones: L-V sin festivos, con
 * recorte opcional al año.
 */
class WorkingDayCounterTest extends TestCase
{
    private WorkingDayCounter $counter;

    protected function setUp(): void
    {
        $this->counter = new WorkingDayCounter();
    }

    private function d(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('Europe/Madrid'));
    }

    public function testLunesAViernesSonCinco(): void
    {
        // 2026-06-15 (lun) a 19 (vie).
        $this->assertSame(5, $this->counter->count($this->d('2026-06-15'), $this->d('2026-06-19'), []));
    }

    public function testElFinDeSemanaNoCuenta(): void
    {
        // Viernes 19 a lunes 22: solo viernes y lunes son laborables.
        $this->assertSame(2, $this->counter->count($this->d('2026-06-19'), $this->d('2026-06-22'), []));
    }

    public function testElFestivoNoCuenta(): void
    {
        // Lun-vie con el miércoles 17 festivo → 4.
        $this->assertSame(4, $this->counter->count($this->d('2026-06-15'), $this->d('2026-06-19'), ['2026-06-17' => 'Festivo']));
    }

    public function testRecorteAlAnio(): void
    {
        // Ausencia 2025-12-29..2026-01-02, recortada a 2026: solo 01-01 (jue) y 01-02 (vie).
        // Con 2026-01-01 festivo (Año Nuevo) → solo 01-02 cuenta.
        $count = $this->counter->count(
            $this->d('2025-12-29'),
            $this->d('2026-01-02'),
            ['2026-01-01' => 'Año Nuevo'],
            $this->d('2026-01-01'),
            $this->d('2026-12-31'),
        );
        $this->assertSame(1, $count);
    }
}
