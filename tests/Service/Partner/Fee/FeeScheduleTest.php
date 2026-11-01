<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\BasketShare;
use App\Entity\EggPeriod;
use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\TransportRate;
use App\Entity\WeeklyBasketGroup;
use App\Service\Partner\Fee\FeeSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Qué precio vale cada día: el último que empezó ese día o antes, nunca uno futuro,
 * y null (no cero) cuando aún no hay ninguno.
 */
class FeeScheduleTest extends TestCase
{
    private function rate(FeeConcept $concept, string $price, string $from): FeeRate
    {
        return new FeeRate($concept, $price, new \DateTimeImmutable($from), null);
    }

    /** @param class-string $class */
    private function withId(string $class, int $id): object
    {
        $entity = new $class();
        $ref = new \ReflectionProperty($class, 'id');
        $ref->setValue($entity, $id);

        return $entity;
    }

    private function schedule(): FeeSchedule
    {
        // Desordenados a propósito: el orden de la base no está garantizado.
        return new FeeSchedule([
            $this->rate(FeeConcept::VegBiweekly, '60.00', '2027-01-01'),
            $this->rate(FeeConcept::VegBiweekly, '55.00', '2026-01-01'),
            $this->rate(FeeConcept::VegBiweekly, '50.00', '2025-01-01'),
            $this->rate(FeeConcept::VegWeekly, '100.00', '2026-01-01'),
            $this->rate(FeeConcept::VegMonthly, '30.00', '2026-01-01'),
            $this->rate(FeeConcept::VegWeeklyShared, '55.00', '2026-01-01'),
            $this->rate(FeeConcept::EggWeekly, '20.00', '2026-01-01'),
        ]);
    }

    public function testValeElUltimoQueYaHaEmpezado(): void
    {
        $s = $this->schedule();

        $this->assertNull($s->rateOn(FeeConcept::VegBiweekly, new \DateTimeImmutable('2024-12-31')), 'Antes del primero no hay precio.');
        $this->assertSame('50.00', $s->rateOn(FeeConcept::VegBiweekly, new \DateTimeImmutable('2025-12-31 23:59'))->getPrice());
        $this->assertSame('55.00', $s->rateOn(FeeConcept::VegBiweekly, new \DateTimeImmutable('2026-01-01'))->getPrice(), 'El mismo día que empieza ya vale.');
        $this->assertSame('55.00', $s->rateOn(FeeConcept::VegBiweekly, new \DateTimeImmutable('2026-10-14'))->getPrice());
        $this->assertSame('60.00', $s->rateOn(FeeConcept::VegBiweekly, new \DateTimeImmutable('2027-03-01'))->getPrice());
    }

    public function testElProximoCambioEsElPrimeroQueAunNoHaEmpezado(): void
    {
        $s = $this->schedule();

        $this->assertSame('60.00', $s->nextAfter(FeeConcept::VegBiweekly, new \DateTimeImmutable('2026-10-14'))->getPrice());
        $this->assertNull($s->nextAfter(FeeConcept::VegBiweekly, new \DateTimeImmutable('2027-01-01')), 'El que empieza hoy ya no es próximo.');
        $this->assertNull($s->nextAfter(FeeConcept::EggMonthly, new \DateTimeImmutable('2026-10-14')));
    }

    public function testElHistorialVaDelMasRecienteAlMasAntiguo(): void
    {
        $prices = array_map(static fn (FeeRate $r): string => $r->getPrice(), $this->schedule()->history(FeeConcept::VegBiweekly));

        $this->assertSame(['60.00', '55.00', '50.00'], $prices);
        $this->assertSame([], $this->schedule()->history(FeeConcept::EggMonthly));
    }

    public function testDiceQueConceptosNoTienenPrecio(): void
    {
        $this->assertSame(
            [FeeConcept::VegBiweeklyShared, FeeConcept::VegMonthlyShared, FeeConcept::EggBiweekly, FeeConcept::EggMonthly],
            $this->schedule()->missingOn(new \DateTimeImmutable('2026-10-14')),
        );
        $this->assertCount(9, (new FeeSchedule([]))->missingOn(new \DateTimeImmutable('2026-10-14')), 'Sin tarifas, falta todo.');
    }

    /**
     * Cada compartida paga su propio precio, no la mitad de su modalidad: la semanal
     * compartida vale 55, no 50. Sin precio propio no se calcula; «solo huevos», cero.
     */
    public function testLaVerduraDeCadaModalidad(): void
    {
        $s = $this->schedule();
        $day = new \DateTimeImmutable('2026-10-14');
        $price = fn (int $id): ?string => $s->vegMonthPrice($this->withId(BasketShare::class, $id), $day);

        $this->assertSame('100.00', $price(BasketShare::ID_WEEKLY));
        $this->assertSame('55.00', $price(BasketShare::ID_WEEKLY_SHARED), 'Su precio, no la mitad de la semanal.');
        $this->assertSame('30.00', $price(BasketShare::ID_MONTHLY));
        $this->assertNull($price(BasketShare::ID_BIWEEKLY_SHARED), 'La quincenal tiene precio, su compartida no: no se saca de la mitad.');
        $this->assertSame('0.00', $price(BasketShare::ID_ONLY_EGG), 'Solo huevos no lleva verdura.');
        $this->assertNull($s->vegMonthPrice($this->withId(BasketShare::class, BasketShare::ID_WEEKLY), new \DateTimeImmutable('2025-06-01')), 'Sin precio ese día: null, nunca cero.');
    }

    public function testLaDocenaDeCadaFrecuencia(): void
    {
        $s = $this->schedule();
        $day = new \DateTimeImmutable('2026-10-14');

        $this->assertSame('20.00', $s->eggDozenMonthPrice($this->withId(EggPeriod::class, EggPeriod::ID_WEEKLY), $day));
        $this->assertNull($s->eggDozenMonthPrice($this->withId(EggPeriod::class, EggPeriod::ID_BIWEEKLY), $day));
    }

    public function testUnPrecioTieneQueSerMayorQueCero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeeRate(FeeConcept::VegWeekly, '0', new \DateTimeImmutable('2026-01-01'), null);
    }

    public function testElPrecioSeGuardaConDosDecimalesYLaFechaSinHora(): void
    {
        $rate = new FeeRate(FeeConcept::VegWeekly, '55.5', new \DateTimeImmutable('2026-01-01 17:30'), null);

        $this->assertSame('55.50', $rate->getPrice());
        $this->assertSame('2026-01-01 00:00:00', $rate->getValidFrom()->format('Y-m-d H:i:s'));
    }

    /**
     * El transporte de una cesta al mes: el precio por reparto de su grupo por los
     * repartos de su modalidad, el que valga ese día; cero sin grupo, sin precio o sin
     * verdura. Los precios llegan desordenados, como de la base.
     */
    public function testElTransporteAlMesEsElPrecioPorRepartoPorLosRepartos(): void
    {
        $cascorro = $this->withId(WeeklyBasketGroup::class, 2);
        $finca = $this->withId(WeeklyBasketGroup::class, 1);
        $s = new FeeSchedule([], [
            new TransportRate($cascorro, '6.00', new \DateTimeImmutable('2027-01-01'), null),
            new TransportRate($cascorro, '5.00', new \DateTimeImmutable('2026-07-01'), null),
        ]);
        $share = fn (int $id): BasketShare => $this->withId(BasketShare::class, $id);
        $oct = new \DateTimeImmutable('2026-10-14');

        $this->assertSame('20.00', $s->transportMonthPrice($cascorro, $share(BasketShare::ID_WEEKLY), $oct));
        $this->assertSame('20.00', $s->transportMonthPrice($cascorro, $share(BasketShare::ID_WEEKLY_SHARED), $oct), 'La compartida recoge en cada reparto de su modalidad.');
        $this->assertSame('10.00', $s->transportMonthPrice($cascorro, $share(BasketShare::ID_BIWEEKLY), $oct));
        $this->assertSame('5.00', $s->transportMonthPrice($cascorro, $share(BasketShare::ID_MONTHLY), $oct));
        $this->assertSame('6.00', $s->transportMonthPrice($cascorro, $share(BasketShare::ID_MONTHLY), new \DateTimeImmutable('2027-01-01')));
        $this->assertSame('0.00', $s->transportMonthPrice($cascorro, $share(BasketShare::ID_MONTHLY), new \DateTimeImmutable('2026-06-30')), 'Antes de tener precio no pagaba.');
        $this->assertSame('0.00', $s->transportMonthPrice($cascorro, $share(BasketShare::ID_ONLY_EGG), $oct), 'Solo huevos no es una cesta.');
        $this->assertSame('0.00', $s->transportMonthPrice($finca, $share(BasketShare::ID_WEEKLY), $oct), 'Un grupo sin precio no paga.');
        $this->assertSame('0.00', $s->transportMonthPrice(null, $share(BasketShare::ID_WEEKLY), $oct), 'Sin grupo, tampoco.');

        $this->assertSame([$cascorro], $s->transportGroups());
        $this->assertSame('6.00', $s->transportNextAfter($cascorro, $oct)?->getPricePerDelivery());
        $this->assertSame(['6.00', '5.00'], array_map(static fn (TransportRate $r): string => $r->getPricePerDelivery(), $s->transportHistory()));
    }

    public function testUnTransporteACeroNoExiste(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TransportRate(new WeeklyBasketGroup(), '0', new \DateTimeImmutable('2026-07-01'), null);
    }
}
