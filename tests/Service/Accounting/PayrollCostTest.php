<?php

namespace App\Tests\Service\Accounting;

use App\Entity\Absence;
use App\Entity\PaySchedule;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Service\Accounting\PayrollCost;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * El coste de los sueldos mes a mes: nóminas ordinarias, pagas extra en su mes (o en
 * el finiquito) y Seguridad Social de la empresa sobre la base prorrateada.
 *
 * Importes inventados: 21.000 € de bruto en 14 pagas da nóminas redondas de 1.500 €.
 *
 * Las bajas médicas imitan la de verdad: empieza el 16 de octubre, así que el día 16 de
 * baja es el 31 de octubre y el 21 es el 5 de noviembre. Base reguladora diaria =
 * 21.000 / 365. «Hoy» es el 14 de noviembre de 2026.
 */
class PayrollCostTest extends TestCase
{
    private const YEAR = 2026;

    private PayrollCost $payroll;

    protected function setUp(): void
    {
        $this->payroll = new PayrollCost(new MockClock('2026-11-14 10:00:00'));
    }

    /**
     * Año entero en 14 pagas: 12 nóminas de 1.500 y la extra en julio y diciembre. La
     * Seguridad Social es la misma todos los meses, sobre 21.000 / 12 = 1.750.
     */
    public function testFourteenPaysPutTheExtrasInJulyAndDecember(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear([$this->salary($worker, '2025-03-01', null, '21000.00', PaySchedule::Fourteen, '31.40')], [], self::YEAR)[1];

        $this->assertSame($worker, $cost['worker']);
        $this->assertSame(1500.0, $cost['gross'][1]);
        $this->assertSame(3000.0, $cost['gross'][7]);
        $this->assertSame(3000.0, $cost['gross'][12]);
        $this->assertSame(21000.0, array_sum($cost['gross']));
        $this->assertSame(array_fill(1, 12, 549.5), $cost['ss']);
    }

    /**
     * En 12 pagas no hay extras aparte: todos los meses igual, y el año suma lo mismo.
     */
    public function testTwelvePaysSpreadTheExtras(): void
    {
        $cost = $this->payroll->forYear([$this->salary($this->worker(1), '2025-03-01', null, '21000.00', PaySchedule::Twelve, '31.40')], [], self::YEAR)[1];

        $this->assertSame(array_fill(1, 12, 1750.0), $cost['gross']);
        $this->assertSame(array_fill(1, 12, 549.5), $cost['ss']);
    }

    /**
     * Subida de sueldo el 1 de julio: el tramo de antes no es un finiquito, así que la
     * extra de julio la paga entera el sueldo viejo y la de diciembre el nuevo.
     */
    public function testARaiseIsNotASettlement(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear([
            $this->salary($worker, '2025-03-01', '2026-06-30', '21000.00', PaySchedule::Fourteen, '30.00'),
            $this->salary($worker, '2026-07-01', null, '22400.00', PaySchedule::Fourteen, '30.00'),
        ], [], self::YEAR)[1];

        $this->assertSame(1500.0, $cost['gross'][6]);
        // Julio: la nómina nueva (22.400 / 14) y la extra de verano del sueldo viejo.
        $this->assertSame(1600.0 + 1500.0, $cost['gross'][7]);
        $this->assertSame(3200.0, $cost['gross'][12]);
        $this->assertSame(525.0, $cost['ss'][6]);
        $this->assertSame(560.0, $cost['ss'][7]);
    }

    /**
     * Temporero del 1 de marzo al 31 de julio: cobra las nóminas de esos meses y, como
     * se va antes de diciembre, la parte de la extra del segundo semestre va en el
     * finiquito de julio junto con la extra de julio.
     */
    public function testATemporaryWorkerGetsTheSecondExtraInTheSettlement(): void
    {
        $cost = $this->payroll->forYear([$this->salary($this->worker(1), '2026-03-01', '2026-07-31', '21000.00', PaySchedule::Fourteen, '30.00')], [], self::YEAR)[1];

        $this->assertSame(0.0, $cost['gross'][2]);
        $this->assertSame(1500.0, $cost['gross'][3]);
        // Julio: su nómina + extra de 122 de 181 días del 1er semestre + 31 de 184 del 2º.
        $this->assertSame(round(1500 + 1500 * 122 / 181 + 1500 * 31 / 184, 2), $cost['gross'][7]);
        $this->assertSame(0.0, $cost['gross'][8]);
        $this->assertSame(0.0, $cost['gross'][12]);
        $this->assertSame(0.0, $cost['ss'][8]);
        $this->assertSame(525.0, $cost['ss'][3]);
    }

    /**
     * Se va el 15 de marzo: marzo es medio mes, y la extra de lo que llevaba de semestre
     * la cobra en el finiquito de marzo, no en julio.
     */
    public function testLeavingMidMonthProratesTheMonthAndSettlesTheExtra(): void
    {
        $cost = $this->payroll->forYear([$this->salary($this->worker(1), '2024-01-01', '2026-03-15', '21000.00', PaySchedule::Fourteen, '30.00')], [], self::YEAR)[1];

        $this->assertSame(round(1500 * 15 / 31 + 1500 * 74 / 181, 2), $cost['gross'][3]);
        $this->assertSame(round(525 * 15 / 31, 2), $cost['ss'][3]);
        $this->assertSame(0.0, $cost['gross'][7]);
        $this->assertSame(0.0, $cost['ss'][4]);
    }

    /**
     * Temporada que acaba el 31 de marzo y otra que empieza el 1 de septiembre: entre
     * medias no cobra, la extra de la primera va en su finiquito de marzo y la de la
     * segunda en diciembre, sólo por lo trabajado.
     */
    public function testAGapBetweenSeasonsSettlesTheFirstOne(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear([
            $this->salary($worker, '2026-01-01', '2026-03-31', '21000.00', PaySchedule::Fourteen, '30.00'),
            $this->salary($worker, '2026-09-01', null, '21000.00', PaySchedule::Fourteen, '30.00'),
        ], [], self::YEAR)[1];

        // Marzo: su nómina + extra de 90 de 181 días del 1er semestre.
        $this->assertSame(round(1500 + 1500 * 90 / 181, 2), $cost['gross'][3]);
        $this->assertSame(0.0, $cost['gross'][4]);
        $this->assertSame(0.0, $cost['gross'][7]);
        $this->assertSame(0.0, $cost['ss'][7]);
        // Diciembre: su nómina + extra de 122 de 184 días del 2º semestre.
        $this->assertSame(round(1500 + 1500 * 122 / 184, 2), $cost['gross'][12]);
    }

    /**
     * Cada trabajador por separado, con su id de clave.
     */
    public function testEachWorkerIsKeptApart(): void
    {
        $costs = $this->payroll->forYear([
            $this->salary($this->worker(1), '2025-01-01', null, '21000.00', PaySchedule::Fourteen, '30.00'),
            $this->salary($this->worker(2), '2025-01-01', null, '12000.00', PaySchedule::Twelve, '30.00'),
        ], [], self::YEAR);

        $this->assertSame([1, 2], array_keys($costs));
        $this->assertSame(1000.0, $costs[2]['gross'][7]);
        $this->assertSame(300.0, $costs[2]['ss'][7]);
    }

    /**
     * Baja complementada al 100 % del 16-oct al 13-nov: la asociación paga el sueldo
     * entero y la Seguridad Social de siempre, pero recupera la prestación desde el día
     * 16 (31-oct al 60 %; 1 a 4-nov al 60 %; 5 a 13-nov al 75 %).
     */
    public function testToppedUpSickLeaveRecoversTheSocialSecurityBenefit(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear(
            [$this->salary($worker, '2025-01-01', null, '21000.00', PaySchedule::Fourteen, '30.00')],
            [$this->sickLeave($worker, '2026-10-16', '2026-11-13', true)],
            self::YEAR,
        )[1];

        $this->assertSame(1500.0, $cost['gross'][10]);
        $this->assertSame(1500.0, $cost['gross'][11]);
        $this->assertSame(3000.0, $cost['gross'][12]);
        $this->assertSame(525.0, $cost['ss'][11]);
        $this->assertSame(-round(21000 / 365 * 0.6, 2), $cost['sickLeaveRefund'][10]);
        $this->assertSame(-round(21000 / 365 * (4 * 0.6 + 9 * 0.75), 2), $cost['sickLeaveRefund'][11]);
        $this->assertSame(0.0, $cost['sickLeaveRefund'][12]);
        $this->assertSame(0.0, $cost['sickLeaveRefund'][9]);
        $this->assertSame(round(1500 + 525 - 21000 / 365 * 9.15, 2), $cost['total'][11]);
        $this->assertSame(3525.0, $cost['total'][12]);
    }

    /**
     * La misma baja SIN complemento: cada día de baja deja de pagarse su parte de la
     * nómina y de la extra de diciembre; la asociación paga sólo el 60 % de los días 4
     * a 15. La Seguridad Social de la empresa sigue igual y no hay nada que recuperar.
     */
    public function testSickLeaveWithoutTopUpPaysOnlyTheEmployerBenefit(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear(
            [$this->salary($worker, '2025-01-01', null, '21000.00', PaySchedule::Fourteen, '30.00')],
            [$this->sickLeave($worker, '2026-10-16', '2026-11-13', false)],
            self::YEAR,
        )[1];

        // Octubre: 16 de 31 días sin nómina; 12 días (del 4.º al 15.º) al 60 %.
        $this->assertSame(round(1500 - 1500 * 16 / 31 + 21000 / 365 * 0.6 * 12, 2), $cost['gross'][10]);
        // Noviembre: 13 de 30 días sin nómina, y ya los paga la Seguridad Social.
        $this->assertSame(850.0, $cost['gross'][11]);
        // Diciembre: la extra del 2º semestre pierde los 29 días de baja de 184.
        $this->assertSame(round(1500 + 1500 * 155 / 184, 2), $cost['gross'][12]);
        $this->assertSame(1500.0, $cost['gross'][7]);
        $this->assertSame(array_fill(1, 12, 525.0), $cost['ss']);
        $this->assertSame(array_fill(1, 12, 0.0), $cost['sickLeaveRefund']);
        $this->assertSame(round(850 + 525, 2), $cost['total'][11]);
    }

    /**
     * Una baja sin fecha de alta se cuenta hasta hoy (14-nov): diciembre se prevé con
     * el sueldo entero, porque no hay dato de que siga de baja.
     */
    public function testOpenSickLeaveCountsOnlyUntilToday(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear(
            [$this->salary($worker, '2025-01-01', null, '21000.00', PaySchedule::Fourteen, '30.00')],
            [$this->sickLeave($worker, '2026-10-16', null, true)],
            self::YEAR,
        )[1];

        $this->assertSame(-round(21000 / 365 * (4 * 0.6 + 10 * 0.75), 2), $cost['sickLeaveRefund'][11]);
        $this->assertSame(0.0, $cost['sickLeaveRefund'][12]);
        $this->assertSame(3525.0, $cost['total'][12]);
    }

    /**
     * Una baja que empezó en diciembre del año anterior sigue numerando sus días desde
     * su inicio: el 1 de enero ya es el día 13 y del 4 al 8 de enero cobra de la
     * Seguridad Social al 60 %, el 9 y el 10 al 75 %.
     */
    public function testSickLeaveCrossingTheYearCountsDaysFromItsStart(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear(
            [$this->salary($worker, '2024-01-01', null, '21000.00', PaySchedule::Fourteen, '30.00')],
            [$this->sickLeave($worker, '2025-12-20', '2026-01-10', true)],
            self::YEAR,
        )[1];

        $this->assertSame(-round(21000 / 365 * (5 * 0.6 + 2 * 0.75), 2), $cost['sickLeaveRefund'][1]);
        $this->assertSame(0.0, $cost['sickLeaveRefund'][2]);
        $this->assertSame(1500.0, $cost['gross'][1]);
    }

    /**
     * Sólo cuentan las bajas médicas aprobadas de esa persona. Una solicitada o
     * cancelada, unas vacaciones o la baja de otra persona no cambian nada; tampoco la
     * baja de quien no tiene sueldo, que ni sale.
     */
    public function testOnlyTheWorkersApprovedSickLeavesCount(): void
    {
        $worker = $this->worker(1);
        $other = $this->worker(2);
        $vacation = $this->sickLeave($worker, '2026-08-03', '2026-08-31', false)->setType(Absence::TYPE_VACATION);
        $costs = $this->payroll->forYear(
            [$this->salary($worker, '2025-01-01', null, '21000.00', PaySchedule::Fourteen, '30.00')],
            [
                $this->sickLeave($worker, '2026-03-01', '2026-03-31', false)->setStatus(Absence::STATUS_REQUESTED),
                $this->sickLeave($worker, '2026-05-01', '2026-05-31', false)->setStatus(Absence::STATUS_CANCELLED),
                $vacation,
                $this->sickLeave($other, '2026-10-01', null, false),
            ],
            self::YEAR,
        );

        $this->assertSame([1], array_keys($costs));
        $this->assertSame(21000.0, array_sum($costs[1]['gross']));
        $this->assertSame(array_fill(1, 12, 0.0), $costs[1]['sickLeaveRefund']);
    }

    /**
     * Un trabajador imaginario (sin ficha) no tiene bajas, aunque se le pasen.
     */
    public function testAnImaginaryWorkerHasNoSickLeave(): void
    {
        $salary = (new WorkerSalary())
            ->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setAnnualGross('21000.00')
            ->setPayments(PaySchedule::Fourteen)
            ->setEmployerSsRate('30.00');

        $cost = $this->payroll->forWorker([$salary], [$this->sickLeave($this->worker(1), '2026-10-16', null, false)], self::YEAR);

        $this->assertNull($cost['worker']);
        $this->assertSame(1500.0, $cost['gross'][11]);
        $this->assertSame(2025.0, $cost['total'][11]);
    }

    /**
     * Una baja médica aprobada; `$end` null = sin fecha de alta.
     */
    private function sickLeave(Worker $worker, string $start, ?string $end, bool $toppedUp): Absence
    {
        return (new Absence())
            ->setWorker($worker)
            ->setType(Absence::TYPE_SICK_LEAVE)
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate($end !== null ? new \DateTimeImmutable($end) : null)
            ->setTopsUpToFullPay($toppedUp);
    }

    /**
     * Un trabajador con id, como lo devuelve la base de datos.
     */
    private function worker(int $id): Worker
    {
        $worker = (new Worker())->setName('Trabajador '.$id);
        (new \ReflectionProperty(Worker::class, 'id'))->setValue($worker, $id);

        return $worker;
    }

    /**
     * Un tramo de sueldo.
     */
    private function salary(Worker $worker, string $from, ?string $to, string $gross, PaySchedule $payments, string $ssRate): WorkerSalary
    {
        return (new WorkerSalary())
            ->setWorker($worker)
            ->setValidFrom(new \DateTimeImmutable($from))
            ->setValidTo($to !== null ? new \DateTimeImmutable($to) : null)
            ->setAnnualGross($gross)
            ->setPayments($payments)
            ->setEmployerSsRate($ssRate);
    }
}
