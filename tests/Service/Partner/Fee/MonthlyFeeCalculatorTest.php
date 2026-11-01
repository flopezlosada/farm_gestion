<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\BasketShare;
use App\Entity\DonationInitiative;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\FeeAdjustment;
use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Entity\PartnerPause;
use App\Service\Partner\Fee\FeeCharge;
use App\Entity\TransportRate;
use App\Entity\WeeklyBasketGroup;
use App\Service\Partner\Fee\FeeSchedule;
use App\Service\Partner\Fee\FeeStatement;
use App\Service\Partner\Fee\MonthlyFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * La cuota de un mes, sin base de datos: agosto de 2026, que tiene cuatro viernes.
 * Cada caso es algo que pasa de verdad: un cambio de modalidad a mitad de mes, una
 * subida de tarifa el día 15, una pausa, una compartida en una familia, una cesta que
 * paga otra persona, una cerrada sin fecha y un precio que falta.
 */
class MonthlyFeeCalculatorTest extends TestCase
{
    private const WEEKS = ['2026-08-07', '2026-08-14', '2026-08-21', '2026-08-28'];

    /** @param class-string $class */
    private static function withId(string $class, int $id): object
    {
        $entity = new $class();
        (new \ReflectionProperty($class, 'id'))->setValue($entity, $id);

        return $entity;
    }

    /** Le pone un id a un socix, como si estuviera guardado. */
    private static function withIdOn(Partner $partner, int $id): Partner
    {
        (new \ReflectionProperty(Partner::class, 'id'))->setValue($partner, $id);

        return $partner;
    }

    private static function partner(string $name, ?Partner $head = null): Partner
    {
        return (new Partner())->setname($name)->setParent($head);
    }

    private static function share(Partner $partner, int $basketShare, ?string $start = null, ?string $end = null, bool $active = true, ?int $eggAmount = null, ?int $eggPeriod = null, ?Partner $payer = null, int $amount = 1): PartnerBasketShare
    {
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare(self::withId(BasketShare::class, $basketShare));
        $share->setAmount($amount);
        $share->setStartDate($start !== null ? new \DateTime($start) : null);
        $share->setEndDate($end !== null ? new \DateTime($end) : null);
        $share->setIsActive($active);
        $share->setEggAmount($eggAmount !== null ? self::withId(EggAmount::class, $eggAmount) : null);
        $share->setEggPeriod($eggPeriod !== null ? self::withId(EggPeriod::class, $eggPeriod) : null);
        $share->setPayerPartner($payer);

        return $share;
    }

    /** Un grupo de recogida que paga transporte: el de Carla y el de las pruebas de transporte. */
    private static ?WeeklyBasketGroup $far = null;

    private static function far(): WeeklyBasketGroup
    {
        return self::$far ??= self::withId(WeeklyBasketGroup::class, 900);
    }

    private static function schedule(): FeeSchedule
    {
        $rate = static fn (FeeConcept $c, string $price, string $from): FeeRate => new FeeRate($c, $price, new \DateTimeImmutable($from), null);

        // Sin precio de la docena mensual: lo que la lleve no se puede calcular.
        return new FeeSchedule([
            $rate(FeeConcept::VegWeekly, '100', '2026-01-01'),
            $rate(FeeConcept::VegBiweekly, '55', '2026-01-01'),
            $rate(FeeConcept::VegBiweekly, '60', '2026-08-15'),
            $rate(FeeConcept::VegBiweeklyShared, '30', '2026-01-01'),
            $rate(FeeConcept::VegMonthly, '30', '2026-01-01'),
            $rate(FeeConcept::EggWeekly, '20', '2026-01-01'),
        ], [
            new TransportRate(self::far(), '2', new \DateTimeImmutable('2026-01-01'), null),
        ]);
    }

    /** @return list<\DateTimeImmutable> */
    private static function weeks(): array
    {
        return array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d), self::WEEKS);
    }

    /** @param list<FeeStatement> $statements */
    private static function of(array $statements, Partner $payer): FeeStatement
    {
        foreach ($statements as $statement) {
            if ($statement->payer === $payer) {
                return $statement;
            }
        }
        self::fail(sprintf('%s no tiene cuota.', $payer->getLegalName()));
    }

    public function testUnMesConTodoLoQuePasa(): void
    {
        $ana = self::partner('Ana');                 // semanal todo el mes con una docena semanal
        $bea = self::partner('Bea');                 // de semanal a quincenal el día 15
        $hogar = self::partner('Hogar');             // encabeza la familia de Carla
        $carla = self::partner('Carla', $hogar);     // quincenal compartida en un grupo con transporte; paga la familia
        $carla->setWeeklyBasketGroup(self::far());
        $dani = self::partner('Dani');               // semanal, en pausa del 10 al 25
        $eva = self::partner('Eva');                 // semanal; se la paga Fran
        $fran = self::partner('Fran');
        $gil = self::partner('Gil');                 // cesta cerrada sin fecha de fin
        $hugo = self::partner('Hugo');               // mensual con docena mensual: sin precio de los huevos
        $rsp = (new DonationInitiative())->setName('Cestas para la RSP');

        $shares = [
            self::share($ana, BasketShare::ID_WEEKLY, eggAmount: 2, eggPeriod: EggPeriod::ID_WEEKLY),
            self::share($bea, BasketShare::ID_WEEKLY, end: '2026-08-14', active: false),
            self::share($bea, BasketShare::ID_BIWEEKLY, start: '2026-08-15'),
            self::share($carla, BasketShare::ID_BIWEEKLY_SHARED, start: '2025-01-01'),
            self::share($dani, BasketShare::ID_WEEKLY),
            self::share($eva, BasketShare::ID_WEEKLY, payer: $fran),
            self::share($gil, BasketShare::ID_WEEKLY, active: false),
            self::share($hugo, BasketShare::ID_MONTHLY, eggAmount: 2, eggPeriod: EggPeriod::ID_MONTHLY),
        ];
        $adjustments = [
            new FeeAdjustment($ana, '20', 'aporta a la RSP', new \DateTimeImmutable('2026-06-01'), null, $rsp),
            new FeeAdjustment($dani, '-10', 'no llega', new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01')),
            new FeeAdjustment($ana, '-99', 'ya terminó', new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-07-01')),
        ];
        $pauses = [new PartnerPause($dani, new \DateTimeImmutable('2026-08-10'), new \DateTimeImmutable('2026-08-25'))];

        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), $shares, self::schedule(), $adjustments, [], $pauses);
        $statements = $fees->getStatements();

        // Ana: 100 de verdura + 20 de una docena semanal + 20 a la RSP. El ajuste que
        // terminó en julio no cuenta.
        $this->assertSame('140.00', self::of($statements, $ana)->getTotal());
        $this->assertSame('120.00', self::of($statements, $ana)->getLines()[0]->getTotal());

        // Bea: dos semanas de semanal (100 × 2/4) y dos de quincenal ya a 60 (60 × 2/4).
        $bea = self::of($statements, $bea);
        $this->assertSame(['50.00', '30.00'], array_map(static fn ($l): string => $l->getTotal(), $bea->getLines()));
        $this->assertTrue($bea->getLines()[0]->isPartial());
        $this->assertSame('80.00', $bea->getTotal());

        // Carla, compartida: el precio de la quincenal compartida (30), que no sigue a la
        // quincenal cuando ésta sube el 15, más el transporte de su grupo (2 € por reparto,
        // dos repartos al mes); lo paga quien encabeza su familia.
        $this->assertSame('34.00', self::of($statements, $hogar)->getTotal());
        $this->assertSame([$carla], self::of($statements, $hogar)->getCoveredPartners());

        // Dani: recoge el 7 y el 28 (en pausa del 10 al 25) y no llega este mes.
        $this->assertSame('40.00', self::of($statements, $dani)->getTotal());

        // La cesta de Eva va en el recibo de Fran.
        $this->assertSame('100.00', self::of($statements, $fran)->getTotal());
        $this->assertSame([$eva], self::of($statements, $fran)->getCoveredPartners());
        $this->assertSame(self::of($statements, $fran), $fees->forPartner($eva), 'Lo de Eva está en el recibo de Fran.');

        // Gil: cerrada sin fecha, no se cobra.
        $payers = array_map(static fn (FeeStatement $s): Partner => $s->payer, $statements);
        $this->assertNotContains($gil, $payers);
        $this->assertNotContains($carla, $payers, 'Lo de la familia va en el recibo de quien la encabeza.');

        // Hugo: falta el precio de la docena mensual; su cuota no se puede dar por buena.
        $hugo = self::of($statements, $hugo);
        $this->assertSame([FeeConcept::EggMonthly], $hugo->getMissingConcepts());
        $this->assertFalse($hugo->isChargeable());
        $this->assertSame([FeeConcept::EggMonthly], $fees->getMissingConcepts());

        $this->assertSame(\count($shares) - 1, array_sum(array_map(static fn (FeeStatement $s): int => \count($s->getLines()), $statements)), 'Una línea por cesta, menos la cerrada de Gil.');
    }

    /** Nadie tiene mandato en este test: ninguna cuota se puede cobrar todavía, y lo dice. */
    public function testSinMandatoNoSeCobra(): void
    {
        $ana = self::partner('Ana');
        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), [self::share($ana, BasketShare::ID_WEEKLY)], self::schedule(), [], []);

        $this->assertSame([], $fees->getChargeable());
        $this->assertStringContainsString('mandato', $fees->getStatements()[0]->getProblems()[0]);

        $ana->setIban('ES9121000418450200051332')->setMandateReference('CUOTA MENSUAL 1')->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $this->assertCount(1, $fees->getChargeable());
    }

    /** Una baja sin vuelta no cobra hasta final de mes; con vuelta, sólo hasta ella. */
    public function testLosPeriodosDeBajaSalenDeLosEventos(): void
    {
        $ana = self::partner('Ana');
        $bea = self::partner('Bea');
        $periods = MonthlyFeeCalculator::stopPeriods([
            new PartnerEvent($ana, PartnerEvent::TYPE_LEAVE, new \DateTimeImmutable('2026-06-29 10:00')),
            new PartnerEvent($ana, PartnerEvent::TYPE_JOIN, new \DateTimeImmutable('2026-06-29 18:00')),
            new PartnerEvent($ana, PartnerEvent::TYPE_LEAVE, new \DateTimeImmutable('2026-08-03')),
            new PartnerEvent($bea, PartnerEvent::TYPE_LEAVE, new \DateTimeImmutable('2026-08-20')),
            new PartnerEvent($bea, PartnerEvent::TYPE_JOIN, new \DateTimeImmutable('2026-08-22')),
        ]);

        $ana = $periods[spl_object_id($ana)];
        $this->assertCount(2, $ana);
        $this->assertNull($ana[1][1], 'La baja del 3 de agosto sigue abierta.');
        $this->assertSame('2026-08-20', $periods[spl_object_id($bea)][0][0]->format('Y-m-d'));
        $this->assertSame('2026-08-22', $periods[spl_object_id($bea)][0][1]->format('Y-m-d'));

        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), [self::share(self::partner('Ana'), BasketShare::ID_WEEKLY)], self::schedule(), [], []);
        $this->assertSame('100.00', $fees->getStatements()[0]->getTotal(), 'Sin eventos se cobra el mes entero.');
    }

    /**
     * Una pausa programada (aún no empezada, sin evento PAUSE todavía) ya descuenta: las
     * cuotas de un mes se preparan antes de que llegue, y leen el mismo periodo que el
     * reparto. Una pausa sin vuelta no cobra hasta final de mes.
     */
    public function testLasPausasProgramadasYaDescuentan(): void
    {
        $ana = self::partner('Ana');
        $bea = self::partner('Bea');
        $pauses = [
            new PartnerPause($ana, new \DateTimeImmutable('2026-08-15')),
            new PartnerPause($bea, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-08-08')),
        ];

        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), [self::share($ana, BasketShare::ID_WEEKLY), self::share($bea, BasketShare::ID_WEEKLY)], self::schedule(), [], [], $pauses);

        $this->assertSame('50.00', self::of($fees->getStatements(), $ana)->getTotal(), 'Recoge el 7 y el 14; desde el 15, nada.');
        $this->assertSame('75.00', self::of($fees->getStatements(), $bea)->getTotal(), 'Vuelve el 8: el 7 no, el 14, 21 y 28 sí.');
    }

    /**
     * Un evento PAUSE suelto ya no cuenta para las cuotas: las pausas sólo salen de sus
     * periodos. Si contara, una pausa se descontaría dos veces o con la fecha en que se
     * anotó en vez de la suya.
     */
    public function testLosEventosDePausaNoCuentan(): void
    {
        $ana = self::partner('Ana');
        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), [self::share($ana, BasketShare::ID_WEEKLY)], self::schedule(), [], [
            new PartnerEvent($ana, PartnerEvent::TYPE_PAUSE, new \DateTimeImmutable('2026-08-10')),
        ]);

        $this->assertSame('100.00', self::of($fees->getStatements(), $ana)->getTotal());
    }

    /**
     * Las cuotas de socix: la inscripción, el mes de la primera cesta del hogar (una por
     * familia, en el recibo de quien paga su cesta); el mantenimiento, en diciembre a
     * cada hogar con cesta ese mes, pero no a quien está en pausa todo el mes. Sin precio
     * del mantenimiento, nadie se puede cobrar.
     */
    public function testLasCuotasDeSocixVanUnaVezPorHogar(): void
    {
        $dec = ['2026-12-04', '2026-12-11', '2026-12-18', '2026-12-25'];
        $weeks = array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d), $dec);
        $rates = [
            new FeeRate(FeeConcept::VegWeekly, '100', new \DateTimeImmutable('2026-01-01'), null),
            new FeeRate(FeeConcept::VegMonthly, '30', new \DateTimeImmutable('2026-01-01'), null),
            new FeeRate(FeeConcept::MembershipSignup, '25', new \DateTimeImmutable('2026-01-01'), null),
        ];
        $withId = static fn (Partner $p, int $id): Partner => self::withIdOn($p, $id);

        $ana = $withId(self::partner('Ana'), 1);             // de siempre
        $nuria = $withId(self::partner('Nuria'), 2);         // nueva: encabeza su familia
        $oscar = $withId(self::partner('Óscar', $nuria), 3); // de la familia de Nuria, con su propia cesta
        $pepa = $withId(self::partner('Pepa'), 4);           // nueva; se la paga Ana
        $quim = $withId(self::partner('Quim'), 5);           // de siempre, en pausa todo diciembre
        $shares = [
            self::share($ana, BasketShare::ID_WEEKLY),
            self::share($nuria, BasketShare::ID_WEEKLY, start: '2026-12-01'),
            self::share($oscar, BasketShare::ID_MONTHLY, start: '2026-12-01'),
            self::share($pepa, BasketShare::ID_MONTHLY, start: '2026-12-10', payer: $ana),
            self::share($quim, BasketShare::ID_WEEKLY),
        ];
        $pauses = [new PartnerPause($quim, new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2027-01-10'))];
        $month = new \DateTimeImmutable('2026-12-01');
        $charges = static fn (FeeStatement $s): array => array_map(static fn (FeeCharge $c): string => $c->concept->value . ' ' . $c->household->getLegalName() . ' ' . $c->getTotal(), $s->getCharges());

        // Sin precio del mantenimiento: cada hogar con cesta lo debe y no se puede cobrar.
        $fees = MonthlyFeeCalculator::compute($month, $weeks, $shares, new FeeSchedule($rates), [], [], $pauses, [2, 4]);
        $this->assertSame([FeeConcept::MembershipUpkeep], $fees->getMissingConcepts());

        $rates[] = new FeeRate(FeeConcept::MembershipUpkeep, '25', new \DateTimeImmutable('2026-01-01'), null);
        $fees = MonthlyFeeCalculator::compute($month, $weeks, $shares, new FeeSchedule($rates), [], [], $pauses, [2, 4]);
        $of = static fn (Partner $p): FeeStatement => self::of($fees->getStatements(), $p);

        // Ana paga su mantenimiento y, con la cesta de Pepa, la inscripción y el mantenimiento de Pepa.
        $this->assertSame(['membership_upkeep Ana 25.00', 'membership_signup Pepa 25.00', 'membership_upkeep Pepa 25.00'], $charges($of($ana)));
        $this->assertSame('122.50', IssuedInvoice::fromCents($of($ana)->getBasketsCents()), 'Su semanal, más 3 de 4 semanas de la mensual de Pepa.');
        $this->assertSame('197.50', $of($ana)->getTotal());
        // Nuria y Óscar son un hogar: una inscripción y un mantenimiento, no dos.
        $this->assertSame(['membership_signup Nuria 25.00', 'membership_upkeep Nuria 25.00'], $charges($of($nuria)));
        $this->assertSame('180.00', $of($nuria)->getTotal(), '100 + 30 de las dos cestas y 50 de cuotas de socix.');
        // Quim no tiene cesta en diciembre: no hay recibo ni mantenimiento.
        $this->assertNotContains($quim, array_map(static fn (FeeStatement $s): Partner => $s->payer, $fees->getStatements()));

        // Fuera de junio y diciembre no hay mantenimiento; la inscripción sólo a quien empieza.
        $nov = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-11-01'), self::weeks(), [self::share($ana, BasketShare::ID_WEEKLY)], new FeeSchedule($rates), [], []);
        $this->assertSame([], $nov->getStatements()[0]->getCharges());
    }

    /**
     * El transporte lo pone el grupo de recogida, por cesta y reparto: a 4 € la cesta,
     * la quincenal paga 8 al mes. Se cobra por cada cesta, sigue al grupo de quien la
     * recoge aunque la pague otra persona, cambia de precio la semana en que cambia, y
     * no lo paga ni un grupo sin precio ni una cesta de solo huevos.
     */
    public function testElTransporteLoPoneElGrupoDeRecogida(): void
    {
        $cascorro = self::withId(WeeklyBasketGroup::class, 901);
        $berrueco = self::withId(WeeklyBasketGroup::class, 902);
        $finca = self::withId(WeeklyBasketGroup::class, 903);
        $schedule = new FeeSchedule([
            new FeeRate(FeeConcept::VegWeekly, '100', new \DateTimeImmutable('2026-01-01'), null),
            new FeeRate(FeeConcept::VegBiweekly, '55', new \DateTimeImmutable('2026-01-01'), null),
            new FeeRate(FeeConcept::VegMonthly, '30', new \DateTimeImmutable('2026-01-01'), null),
            new FeeRate(FeeConcept::EggWeekly, '24', new \DateTimeImmutable('2026-01-01'), null),
        ], [
            new TransportRate($cascorro, '4', new \DateTimeImmutable('2026-01-01'), null),
            new TransportRate($cascorro, '5', new \DateTimeImmutable('2026-08-15'), null),
            new TransportRate($berrueco, '3', new \DateTimeImmutable('2026-01-01'), null),
        ]);
        $in = static fn (string $name, WeeklyBasketGroup $group): Partner => self::partner($name)->setWeeklyBasketGroup($group);

        $irene = $in('Irene', $cascorro);   // dos quincenales en Cascorro
        $juan = $in('Juan', $berrueco);     // mensual en El Berrueco
        $kike = $in('Kike', $cascorro);     // solo huevos en Cascorro
        $lola = $in('Lola', $finca);        // semanal en la finca, y paga la de Mario
        $mario = $in('Mario', $berrueco);   // semanal en El Berrueco, la paga Lola

        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), [
            self::share($irene, BasketShare::ID_BIWEEKLY, amount: 2),
            self::share($juan, BasketShare::ID_MONTHLY),
            self::share($kike, BasketShare::ID_ONLY_EGG, eggAmount: 1, eggPeriod: EggPeriod::ID_WEEKLY),
            self::share($lola, BasketShare::ID_WEEKLY),
            self::share($mario, BasketShare::ID_WEEKLY, payer: $lola),
        ], $schedule, [], []);
        $transport = static fn (Partner $payer): array => array_map(static fn ($l): int => $l->transportCents, self::of($fees->getStatements(), $payer)->getLines());

        // Dos cestas × dos repartos: 4 € las semanas del 7 y el 14, 5 € las del 21 y el 28.
        $this->assertSame([1800], $transport($irene));
        $this->assertSame([300], $transport($juan), 'El Berrueco: 3 € a la mensual.');
        $this->assertSame([0], $transport($kike), 'Solo huevos no es una cesta: no paga transporte.');
        $this->assertSame([0, 1200], $transport($lola), 'La de Lola no paga; la de Mario, la de su grupo, aunque la pague Lola.');
        $this->assertSame('212.00', self::of($fees->getStatements(), $lola)->getTotal());
    }

    /** Sin semanas de reparto dadas de alta no hay nada que prorratear: nadie paga. */
    public function testSinSemanasNoHayCuotas(): void
    {
        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), [], [self::share(self::partner('Ana'), BasketShare::ID_WEEKLY)], self::schedule(), [], []);

        $this->assertSame([], $fees->getStatements());
    }
}
