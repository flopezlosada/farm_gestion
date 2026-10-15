<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\BasketShare;
use App\Entity\EggPeriod;
use App\Entity\FeeConcept;
use App\Entity\FeeRate;
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
            $this->rate(FeeConcept::VegMonthly, '30.05', '2026-01-01'),
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
            [FeeConcept::EggBiweekly, FeeConcept::EggMonthly],
            $this->schedule()->missingOn(new \DateTimeImmutable('2026-10-14')),
        );
        $this->assertCount(6, (new FeeSchedule([]))->missingOn(new \DateTimeImmutable('2026-10-14')), 'Sin tarifas, falta todo.');
    }

    /** La compartida paga la mitad de su modalidad, redondeada al céntimo; «solo huevos», cero. */
    public function testLaVerduraDeCadaModalidad(): void
    {
        $s = $this->schedule();
        $day = new \DateTimeImmutable('2026-10-14');
        $price = fn (int $id): ?string => $s->vegMonthPrice($this->withId(BasketShare::class, $id), $day);

        $this->assertSame('100.00', $price(BasketShare::ID_WEEKLY));
        $this->assertSame('50.00', $price(BasketShare::ID_WEEKLY_SHARED));
        $this->assertSame('27.50', $price(BasketShare::ID_BIWEEKLY_SHARED));
        $this->assertSame('15.03', $price(BasketShare::ID_MONTHLY_SHARED), '30,05 / 2 = 15,025: se redondea hacia arriba.');
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
}
