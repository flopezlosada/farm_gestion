<?php

namespace App\Tests\Service\Accounting;

use App\Entity\PaySchedule;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Service\Accounting\PayrollCost;
use PHPUnit\Framework\TestCase;

/**
 * El coste de los sueldos mes a mes: nóminas ordinarias, pagas extra en su mes (o en
 * el finiquito) y Seguridad Social de la empresa sobre la base prorrateada.
 *
 * Importes inventados: 21.000 € de bruto en 14 pagas da nóminas redondas de 1.500 €.
 */
class PayrollCostTest extends TestCase
{
    private const YEAR = 2026;

    private PayrollCost $payroll;

    protected function setUp(): void
    {
        $this->payroll = new PayrollCost();
    }

    /**
     * Año entero en 14 pagas: 12 nóminas de 1.500 y la extra en julio y diciembre. La
     * Seguridad Social es la misma todos los meses, sobre 21.000 / 12 = 1.750.
     */
    public function testFourteenPaysPutTheExtrasInJulyAndDecember(): void
    {
        $worker = $this->worker(1);
        $cost = $this->payroll->forYear([$this->salary($worker, '2025-03-01', null, '21000.00', PaySchedule::Fourteen, '31.40')], self::YEAR)[1];

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
        $cost = $this->payroll->forYear([$this->salary($this->worker(1), '2025-03-01', null, '21000.00', PaySchedule::Twelve, '31.40')], self::YEAR)[1];

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
        ], self::YEAR)[1];

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
        $cost = $this->payroll->forYear([$this->salary($this->worker(1), '2026-03-01', '2026-07-31', '21000.00', PaySchedule::Fourteen, '30.00')], self::YEAR)[1];

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
        $cost = $this->payroll->forYear([$this->salary($this->worker(1), '2024-01-01', '2026-03-15', '21000.00', PaySchedule::Fourteen, '30.00')], self::YEAR)[1];

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
        ], self::YEAR)[1];

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
        ], self::YEAR);

        $this->assertSame([1, 2], array_keys($costs));
        $this->assertSame(1000.0, $costs[2]['gross'][7]);
        $this->assertSame(300.0, $costs[2]['ss'][7]);
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
