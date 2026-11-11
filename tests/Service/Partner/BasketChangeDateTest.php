<?php

namespace App\Tests\Service\Partner;

use App\Repository\RemittanceRepository;
use App\Service\Partner\BasketChangeDate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Desde cuándo vale un cambio de cesta: el día 1 del mes siguiente, salvo que ése ya
 * esté cobrado. Nunca desde el mes en curso, aunque su remesa no se haya mandado.
 */
class BasketChangeDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, string}> hoy, primer mes sin cobrar, fecha del cambio
     */
    public static function cases(): iterable
    {
        yield 'sin ninguna remesa enviada' => ['2026-11-11 18:30', null, '2026-12-01'];
        yield 'la del mes en curso sin mandar: no es retroactivo' => ['2026-11-11 09:00', '2026-11-01', '2026-12-01'];
        yield 'la del mes en curso ya mandada' => ['2026-11-11 09:00', '2026-12-01', '2026-12-01'];
        yield 'la del mes siguiente ya mandada por adelantado' => ['2026-11-28 09:00', '2027-01-01', '2027-01-01'];
        yield 'en diciembre pasa de año' => ['2026-12-31 23:59', '2026-12-01', '2027-01-01'];
        yield 'el día 1 también espera al mes siguiente' => ['2026-11-01 00:00', null, '2026-12-01'];
    }

    /**
     * @dataProvider cases
     */
    public function testElCambioValeDesdeElMesSiguiente(string $now, ?string $firstUncharged, string $expected): void
    {
        $remittances = $this->createStub(RemittanceRepository::class);
        $remittances->method('firstUnchargedBasketMonth')->willReturn($firstUncharged !== null ? new \DateTimeImmutable($firstUncharged) : null);

        $date = (new BasketChangeDate($remittances, new MockClock(new \DateTimeImmutable($now))))->next();

        $this->assertSame($expected . ' 00:00', $date->format('Y-m-d H:i'));
    }
}
