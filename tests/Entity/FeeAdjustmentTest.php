<?php

namespace App\Tests\Entity;

use App\Entity\DonationInitiative;
use App\Entity\FeeAdjustment;
use App\Entity\Partner;
use PHPUnit\Framework\TestCase;

/**
 * Un ajuste de cuota vale por meses enteros, los dos extremos incluidos; uno de un
 * solo mes tiene el mismo en los dos. Y no puede existir uno que no tiene sentido:
 * de cero, sin motivo, que termina antes de empezar, o una reducción donada.
 */
class FeeAdjustmentTest extends TestCase
{
    private function adjustment(string $amount = '20', string $from = '2026-09-15', ?string $until = null, ?DonationInitiative $initiative = null): FeeAdjustment
    {
        return new FeeAdjustment(new Partner(), $amount, 'amplía su cuota', new \DateTimeImmutable($from), $until !== null ? new \DateTimeImmutable($until) : null, $initiative);
    }

    public function testValePorMesesEnterosConLosExtremosIncluidos(): void
    {
        $a = $this->adjustment(from: '2026-09-15', until: '2026-11-03');

        $this->assertSame('2026-09-01', $a->getFromMonth()->format('Y-m-d'), 'Cualquier día cuenta como su mes.');
        $this->assertSame('2026-11-01', $a->getUntilMonth()->format('Y-m-d'));
        $this->assertFalse($a->appliesTo(new \DateTimeImmutable('2026-08-31')));
        $this->assertTrue($a->appliesTo(new \DateTimeImmutable('2026-09-01')));
        $this->assertTrue($a->appliesTo(new \DateTimeImmutable('2026-11-30')));
        $this->assertFalse($a->appliesTo(new \DateTimeImmutable('2026-12-01')));
        $this->assertTrue($a->isUpcomingIn(new \DateTimeImmutable('2026-08-20')));
        $this->assertTrue($a->isOverBy(new \DateTimeImmutable('2026-12-01')));
        $this->assertFalse($a->isOverBy(new \DateTimeImmutable('2026-11-30')));
    }

    public function testSinFinValeSiempreDesdeQueEmpieza(): void
    {
        $a = $this->adjustment(from: '2026-09-01');

        $this->assertTrue($a->appliesTo(new \DateTimeImmutable('2030-01-01')));
        $this->assertFalse($a->isOverBy(new \DateTimeImmutable('2030-01-01')));
    }

    public function testUnSoloMes(): void
    {
        $a = $this->adjustment('-15', '2026-09-01', '2026-09-30');

        $this->assertTrue($a->appliesTo(new \DateTimeImmutable('2026-09-20')));
        $this->assertFalse($a->appliesTo(new \DateTimeImmutable('2026-10-01')));
        $this->assertSame('-15.00', $a->getAmount());
        $this->assertFalse($a->isIncrease());
    }

    public function testTerminarloEsPonerleElUltimoMes(): void
    {
        $a = $this->adjustment(from: '2026-09-01');
        $a->endIn(new \DateTimeImmutable('2026-10-14'));

        $this->assertSame('2026-10-01', $a->getUntilMonth()->format('Y-m-d'));
        $this->assertFalse($a->appliesTo(new \DateTimeImmutable('2026-11-01')));

        $this->expectException(\InvalidArgumentException::class);
        $a->endIn(new \DateTimeImmutable('2026-08-01'));
    }

    public function testUnaAportacionPuedeIrAUnaIniciativa(): void
    {
        $rsp = (new DonationInitiative())->setName('Cestas para la RSP');

        $this->assertSame($rsp, $this->adjustment('20', initiative: $rsp)->getInitiative());
    }

    /** @return iterable<string, array{string, ?string, ?string, bool, string}> */
    public static function nonsense(): iterable
    {
        yield 'de cero' => ['0', '2026-09-01', null, false, 'amplía'];
        yield 'una reducción donada' => ['-10', '2026-09-01', null, true, 'amplía'];
        yield 'termina antes de empezar' => ['20', '2026-09-01', '2026-08-01', false, 'amplía'];
        yield 'sin motivo' => ['20', '2026-09-01', null, false, '   '];
    }

    /** @dataProvider nonsense */
    public function testLoQueNoTieneSentidoNoSeCrea(string $amount, string $from, ?string $until, bool $donated, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeeAdjustment(
            new Partner(),
            $amount,
            $reason,
            new \DateTimeImmutable($from),
            $until !== null ? new \DateTimeImmutable($until) : null,
            $donated ? (new DonationInitiative())->setName('RSP') : null,
        );
    }
}
