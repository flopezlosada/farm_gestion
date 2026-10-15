<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\PartnerPause;
use PHPUnit\Framework\TestCase;

/**
 * El periodo de una pausa es semiabierto, [inicio, vuelta): el día de vuelta ya
 * hay cesta. Es el mismo convenio que las cuotas y el reparto; si uno de los dos
 * extremos se interpretara distinto, una semana se cobraría sin repartirse o al revés.
 */
class PartnerPauseTest extends TestCase
{
    private static function pause(string $from, ?string $until = null): PartnerPause
    {
        return new PartnerPause(new Partner(), new \DateTimeImmutable($from), $until !== null ? new \DateTimeImmutable($until) : null);
    }

    public function testElDiaDeInicioEstaEnPausaYElDeVueltaNo(): void
    {
        $pause = self::pause('2026-11-01', '2026-11-15');

        $this->assertFalse($pause->covers(new \DateTimeImmutable('2026-10-31')));
        $this->assertTrue($pause->covers(new \DateTimeImmutable('2026-11-01 00:00')));
        $this->assertTrue($pause->covers(new \DateTimeImmutable('2026-11-14 23:59')), 'La hora no cuenta: el último día sin cesta lo es entero.');
        $this->assertFalse($pause->covers(new \DateTimeImmutable('2026-11-15')), 'El día de vuelta ya hay cesta.');
    }

    public function testSinVueltaDuraSiempre(): void
    {
        $pause = self::pause('2026-11-01');

        $this->assertTrue($pause->covers(new \DateTimeImmutable('2099-01-01')));
        $this->assertFalse($pause->endedBefore(new \DateTimeImmutable('2099-01-01')));
    }

    public function testLaVueltaTieneQueSerPosteriorAlInicio(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::pause('2026-11-01', '2026-11-01');
    }

    /** Dos pausas seguidas (una vuelve el día que empieza la otra) no se solapan. */
    public function testSolapes(): void
    {
        $pause = self::pause('2026-11-01', '2026-11-15');

        $this->assertFalse($pause->overlaps(new \DateTimeImmutable('2026-11-15'), null), 'Empieza el día que vuelve: seguidas, no solapadas.');
        $this->assertFalse($pause->overlaps(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01')));
        $this->assertTrue($pause->overlaps(new \DateTimeImmutable('2026-11-14'), null));
        $this->assertTrue($pause->overlaps(new \DateTimeImmutable('2026-10-01'), null), 'Una sin fin que empieza antes la tapa.');
        $this->assertTrue(self::pause('2026-11-01')->overlaps(new \DateTimeImmutable('2030-01-01'), new \DateTimeImmutable('2030-02-01')));
    }

    public function testTerminadaAntesDe(): void
    {
        $pause = self::pause('2026-11-01', '2026-11-15');

        $this->assertFalse($pause->endedBefore(new \DateTimeImmutable('2026-11-14')));
        $this->assertTrue($pause->endedBefore(new \DateTimeImmutable('2026-11-15')), 'El día de vuelta ya no está en pausa.');
    }
}
