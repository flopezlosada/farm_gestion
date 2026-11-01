<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\BasketShare;
use App\Entity\DonationInitiative;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\FeeAdjustment;
use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Entity\PartnerPause;
use App\Entity\Worker;
use App\Entity\WorkerLeaveBasket;
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

    private static function partner(string $name, ?Partner $head = null): Partner
    {
        return (new Partner())->setname($name)->setParent($head);
    }

    private static function share(Partner $partner, int $basketShare, ?string $start = null, ?string $end = null, bool $active = true, ?int $eggAmount = null, ?int $eggPeriod = null, ?Partner $payer = null, ?string $transport = null): PartnerBasketShare
    {
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare(self::withId(BasketShare::class, $basketShare));
        $share->setAmount(1);
        $share->setStartDate($start !== null ? new \DateTime($start) : null);
        $share->setEndDate($end !== null ? new \DateTime($end) : null);
        $share->setIsActive($active);
        $share->setEggAmount($eggAmount !== null ? self::withId(EggAmount::class, $eggAmount) : null);
        $share->setEggPeriod($eggPeriod !== null ? self::withId(EggPeriod::class, $eggPeriod) : null);
        $share->setPayerPartner($payer);
        $share->setTransportPrice($transport);

        return $share;
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
        $carla = self::partner('Carla', $hogar);     // quincenal compartida; paga la familia
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
            self::share($carla, BasketShare::ID_BIWEEKLY_SHARED, start: '2025-01-01', transport: '4.00'),
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
        // quincenal cuando ésta sube el 15, más el transporte; lo paga quien encabeza su familia.
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

    /** Sin semanas de reparto dadas de alta no hay nada que prorratear: nadie paga. */
    public function testSinSemanasNoHayCuotas(): void
    {
        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), [], [self::share(self::partner('Ana'), BasketShare::ID_WEEKLY)], self::schedule(), [], []);

        $this->assertSame([], $fees->getStatements());
    }

    /**
     * Trabajadorx con ficha de socix, como sale de la base: alta, baja y la decisión
     * sobre su cesta, con lo que esté a null si no se ha rellenado.
     */
    private static function worker(Partner $partner, string $hire, ?string $leave = null, ?WorkerLeaveBasket $after = null, ?string $giftUntil = null): Worker
    {
        return (new Worker())
            ->setName($partner->getName())
            ->setHireDate(new \DateTimeImmutable($hire))
            ->setLeaveDate($leave !== null ? new \DateTimeImmutable($leave) : null)
            ->setPartner($partner)
            ->setBasketAfterLeave($after)
            ->setBasketGiftUntil($giftUntil !== null ? new \DateTimeImmutable($giftUntil) : null);
    }

    /** Con mandato, para que lo único que pueda fallar sea lo de la cesta de trabajador. */
    private static function withMandate(Partner $partner): Partner
    {
        return $partner->setIban('ES9121000418450200051332')->setMandateReference('CUOTA 1')->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
    }

    /** @param list<Worker> $workers */
    private static function fees(array $shares, array $workers): array
    {
        return MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), $shares, self::schedule(), [], [], [], $workers)->getStatements();
    }

    /**
     * Quien trabaja todo el mes no paga nada, ni verdura ni huevos, y no es un recibo
     * que falle: no hay recibo. Ni siquiera hace falta que tenga mandato.
     */
    public function testQuienTrabajaNoPagaSuCesta(): void
    {
        $alba = self::partner('Alba');
        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-08-01'), self::weeks(), [self::share($alba, BasketShare::ID_WEEKLY, eggAmount: 2, eggPeriod: EggPeriod::ID_WEEKLY)], self::schedule(), [], [], [], [self::worker($alba, '2017-06-09')]);
        $statement = self::of($fees->getStatements(), $alba);

        $this->assertSame('0.00', $statement->getTotal());
        $this->assertTrue($statement->getLines()[0]->isFree());
        $this->assertSame(4, $statement->getLines()[0]->freeWeeks);
        $this->assertTrue($statement->isFree());
        $this->assertSame([], $statement->getProblems(), 'Sin mandato y a 0 €, pero no es un problema.');
        $this->assertSame([], $fees->getChargeable());
        $this->assertSame(0, $fees->getBasketTotals()->vegCents, 'Lo regalado no es ingreso.');
    }

    /** Quien entra a trabajar a mitad de mes paga las semanas de antes, y el desglose lo explica. */
    public function testQuienEntraAMitadDeMesPagaLasSemanasDeAntes(): void
    {
        $alba = self::withMandate(self::partner('Alba'));
        $statement = self::of(self::fees([self::share($alba, BasketShare::ID_WEEKLY)], [self::worker($alba, '2026-08-12')]), $alba);

        $this->assertSame('25.00', $statement->getTotal(), 'Sólo la semana del 7.');
        $this->assertSame(3, $statement->getLines()[0]->freeWeeks);
        $this->assertTrue($statement->needsBreakdown());
        $this->assertTrue($statement->isChargeable());
    }

    /**
     * Quien se va y nadie decide qué pasa con su cesta: las semanas que trabajó son
     * gratis, las de después se calculan, pero la cuota no se cobra hasta decidirlo.
     */
    public function testUnaBajaSinDecidirNoSeCobra(): void
    {
        $alba = self::withMandate(self::partner('Alba'));
        $statement = self::of(self::fees([self::share($alba, BasketShare::ID_WEEKLY)], [self::worker($alba, '2020-01-01', '2026-08-17')]), $alba);

        $this->assertSame('50.00', $statement->getTotal(), 'Gratis el 7 y el 14; el 21 y el 28, sin decidir.');
        $this->assertSame(2, $statement->getLines()[0]->undecidedWeeks);
        $this->assertFalse($statement->isChargeable());
        $this->assertStringContainsString('decidir', $statement->getProblems()[0]);
    }

    /** Tras la baja, o la paga como cualquiera o se le regala, sin fin o hasta un día. */
    public function testTrasLaBajaLaPagaOSeLeRegala(): void
    {
        $paga = self::withMandate(self::partner('Paga'));
        $hasta = self::withMandate(self::partner('Hasta'));
        $siempre = self::partner('Siempre');
        $statements = self::fees(
            [self::share($paga, BasketShare::ID_WEEKLY), self::share($hasta, BasketShare::ID_WEEKLY), self::share($siempre, BasketShare::ID_WEEKLY)],
            [
                self::worker($paga, '2020-01-01', '2026-08-17', WorkerLeaveBasket::Paid),
                self::worker($hasta, '2020-01-01', '2026-08-17', WorkerLeaveBasket::Gift, '2026-08-24'),
                self::worker($siempre, '2020-01-01', '2026-08-17', WorkerLeaveBasket::Gift),
            ],
        );

        $this->assertSame('50.00', self::of($statements, $paga)->getTotal());
        $this->assertTrue(self::of($statements, $paga)->isChargeable());
        $this->assertSame('25.00', self::of($statements, $hasta)->getTotal(), 'Regalo hasta el 24: sólo paga el 28.');
        $this->assertTrue(self::of($statements, $hasta)->isChargeable());
        $this->assertTrue(self::of($statements, $siempre)->isFree());
    }

    /** Un ajuste que suma convierte la cuota gratis en un recibo normal: lo de más sí se cobra. */
    public function testUnaAportacionSobreLaCestaGratisSeCobra(): void
    {
        $alba = self::withMandate(self::partner('Alba'));
        $statement = self::of(MonthlyFeeCalculator::compute(
            new \DateTimeImmutable('2026-08-01'), self::weeks(), [self::share($alba, BasketShare::ID_WEEKLY)], self::schedule(),
            [new FeeAdjustment($alba, '10', 'aporta a la RSP', new \DateTimeImmutable('2026-08-01'), null)], [], [], [self::worker($alba, '2017-06-09')],
        )->getStatements(), $alba);

        $this->assertFalse($statement->isFree());
        $this->assertSame('10.00', $statement->getTotal());
        $this->assertTrue($statement->isChargeable());
    }

    /** Pagarla no tiene «hasta»: elegirlo borra la fecha del regalo, y sin regalo no se guarda. */
    public function testPagarlaNoTieneFechaDeRegalo(): void
    {
        $worker = self::worker(self::partner('Alba'), '2020-01-01', '2026-08-17', WorkerLeaveBasket::Gift, '2026-12-31');
        $worker->setBasketAfterLeave(WorkerLeaveBasket::Paid);
        $this->assertNull($worker->getBasketGiftUntil());

        $worker->setBasketGiftUntil(new \DateTimeImmutable('2026-12-31'));
        $this->assertNull($worker->getBasketGiftUntil());
    }
}
