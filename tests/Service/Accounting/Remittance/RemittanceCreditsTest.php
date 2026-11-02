<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\AccountEntry;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Repository\AccountEntryRepository;
use App\Repository\RemittanceRepository;
use App\Repository\RemittanceReturnRepository;
use App\Service\Accounting\Remittance\BankReturns;
use App\Service\Accounting\Remittance\RemittanceCredits;
use PHPUnit\Framework\TestCase;

/**
 * Qué abonos del banco son de una remesa y cuánto falta. Las cifras son las de Fiare en
 * 2026: septiembre entró en dos lotes el mismo día (150,00 y 7.437,40) para 7.696,40
 * pedidos, y agosto tuvo un cobro suelto de 97,00 el día 4 que no es la remesa.
 */
class RemittanceCreditsTest extends TestCase
{
    private int $nextId = 1;

    /** Los del día que más se acerca a lo pedido, aunque haya otro abono suelto en el mes. */
    public function testLosLotesSonLosAbonosDelDiaQueMasSeAcercaALoPedido(): void
    {
        $august = $this->remittance('2026-08-01', ['7472.40']);
        $loose = $this->entry('2026-08-04', '97.00', 'ab.rem.20260015586');
        $main = $this->entry('2026-08-18', '7417.40', 'ab.rem.20260016561');
        $sameDay = $this->entry('2026-08-18', '25.00', 'ab.rem.20260016562');
        $notACredit = $this->entry('2026-08-18', '-38.55', 'bel-liq.rem.20260016561');
        $nextMonth = $this->entry('2026-09-09', '7437.40', 'ab.rem.20260017554');

        $lots = RemittanceCredits::pickLots(
            [$loose, $main, $sameDay, $notACredit, $nextMonth],
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
            $august,
        );

        $this->assertSame([$main, $sameDay], $lots);
        $this->assertSame([], RemittanceCredits::pickLots([$nextMonth], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-31'), $august));

        $small = $this->remittance('2026-08-01', ['100.00']);
        $this->assertSame([$loose], RemittanceCredits::pickLots([$loose, $main], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-31'), $small), 'Una remesa pequeña casa con el abono pequeño, no con el mayor.');
    }

    /** Sin día de cargo se busca en su mes; con él, en su margen de días hábiles. */
    public function testDondeSeBuscaElAbono(): void
    {
        $imported = $this->remittance('2026-09-01', ['7696.40']);
        [$from, $to] = RemittanceCredits::range($imported);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        $imported->rescheduleCharge(new \DateTimeImmutable('2026-10-13'));
        [$from, $to] = RemittanceCredits::range($imported);
        $this->assertSame(['2026-10-09', '2026-10-15'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);
    }

    /** Septiembre: faltan 109 € del abono; uno ya dado por devuelto sin apunte los rebaja. */
    public function testLoQueFaltaDelAbono(): void
    {
        $rejected = $this->line('ZZTEST ANA', '55.00');
        $other = $this->line('ZZTEST LUIS', '54.00');
        $september = $this->remittance('2026-09-01', ['7587.40'], [$rejected, $other]);
        $lots = [$this->entry('2026-09-09', '150.00', 'ab.rem.20260017553'), $this->entry('2026-09-09', '7437.40', 'ab.rem.20260017554')];

        $this->assertSame(10900, RemittanceCredits::missingCents($september, $lots, []));
        $this->assertSame(5400, RemittanceCredits::missingCents($september, $lots, [new RemittanceReturn($rejected, null, null)]));
        $withEntry = new RemittanceReturn($other, $this->entry('2026-09-10', '-54.00', 'bel-liq.rem.devol20260017554'), null);
        $this->assertSame(10900, RemittanceCredits::missingCents($september, $lots, [$withEntry]), 'Una devolución con apunte ya restó del banco: no explica lo que falta del abono.');

        $august = $this->remittance('2026-08-01', ['55.00']);
        $ofAnotherRemittance = new RemittanceReturn($august->getLines()->first(), null, null);
        $this->assertSame(10900, RemittanceCredits::missingCents($september, $lots, [$ofAnotherRemittance]), 'Lo marcado en otra remesa no cuenta.');
    }

    /** Si entró más de lo pedido, la diferencia sale negativa (hay que mirarlo igual). */
    public function testSiEntraDeMasSaleNegativo(): void
    {
        $remittance = $this->remittance('2026-10-01', ['100.00']);

        $this->assertSame(-2500, RemittanceCredits::missingCents($remittance, [$this->entry('2026-10-13', '125.00', 'ab.rem.1')], []));
    }

    /**
     * Las devoluciones sin ligar que se piden identificar son las de los lotes de las
     * remesas de aquí; la de un cobro suelto (otro día del mes) no tiene recibo y no se
     * pide. Con una sola consulta de abonos para todas las remesas.
     */
    public function testSoloSePidenLasDevolucionesDeLasRemesasDeAqui(): void
    {
        $august = $this->remittance('2026-08-01', ['7472.40']);
        $september = $this->remittance('2026-09-01', ['7696.40']);
        $credits = [
            $this->entry('2026-08-04', '97.00', 'ab.rem.20260015586'),
            $this->entry('2026-08-18', '7417.40', 'ab.rem.20260016561'),
            $this->entry('2026-09-09', '150.00', 'ab.rem.20260017553'),
            $this->entry('2026-09-09', '7437.40', 'ab.rem.20260017554'),
        ];
        $ofAugust = $this->entry('2026-08-19', '-55.00', 'bel-liq.rem.devol20260016561');
        $ofLoose = $this->entry('2026-08-06', '-97.00', 'bel-liq.rem.devol20260015586');
        $ofSeptember = $this->entry('2026-09-10', '-100.00', 'bel-liq.rem.devol20260017554');

        $remittances = $this->createStub(RemittanceRepository::class);
        $remittances->method('findAllNewestFirst')->willReturn([$september, $august]);
        $remittances->method('firstPeriod')->willReturn(new \DateTimeImmutable('2026-08-01'));
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->expects($this->once())->method('findByConceptPrefix')
            ->with(BankReturns::CREDIT_PREFIX, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-09-30'))
            ->willReturn($credits);
        $entries->method('findUnlinkedReturnCharges')->willReturn([$ofLoose, $ofAugust, $ofSeptember]);

        $service = new RemittanceCredits($remittances, $entries, $this->createStub(RemittanceReturnRepository::class));

        $this->assertSame([$ofAugust, $ofSeptember], $service->unidentifiedReturns());
    }

    /** Sin ninguna remesa aquí, ninguna devolución tiene recibo con el que ligarse. */
    public function testSinRemesasNoSePideNada(): void
    {
        $remittances = $this->createStub(RemittanceRepository::class);
        $remittances->method('firstPeriod')->willReturn(null);
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->expects($this->never())->method('findUnlinkedReturnCharges');

        $this->assertSame([], (new RemittanceCredits($remittances, $entries, $this->createStub(RemittanceReturnRepository::class)))->unidentifiedReturns());
    }

    /** Las de agosto y septiembre de verdad: 55 € y 109 € por identificar; la que cuadra no sale. */
    public function testLasRemesasQueNoCuadran(): void
    {
        $august = $this->remittance('2026-08-01', ['7472.40']);
        $september = $this->remittance('2026-09-01', ['7696.40']);
        $july = $this->remittance('2026-07-01', ['7494.40']);
        $credits = [
            $this->entry('2026-07-15', '55.00', 'ab.rem.20260014498'),
            $this->entry('2026-07-15', '25.00', 'ab.rem.20260014499'),
            $this->entry('2026-07-15', '7414.40', 'ab.rem.20260014500'),
            $this->entry('2026-08-04', '97.00', 'ab.rem.20260015586'),
            $this->entry('2026-08-18', '7417.40', 'ab.rem.20260016561'),
            $this->entry('2026-09-09', '150.00', 'ab.rem.20260017553'),
            $this->entry('2026-09-09', '7437.40', 'ab.rem.20260017554'),
        ];
        $remittances = $this->createStub(RemittanceRepository::class);
        $remittances->method('findAllNewestFirst')->willReturn([$september, $august, $july]);
        $entries = $this->createStub(AccountEntryRepository::class);
        $entries->method('findByConceptPrefix')->willReturn($credits);
        $returns = $this->createStub(RemittanceReturnRepository::class);
        $returns->method('findBy')->willReturn([]);

        $rows = (new RemittanceCredits($remittances, $entries, $returns))->mismatches();

        $this->assertSame([[$september, 10900], [$august, 5500]], array_map(static fn (array $r): array => [$r['remittance'], $r['missingCents']], $rows));
    }

    /**
     * Una remesa importada con sus recibos.
     *
     * @param list<string>         $amounts Importes de recibos de relleno.
     * @param list<RemittanceLine> $lines   Recibos con nombre, de más.
     */
    private function remittance(string $period, array $amounts, array $lines = []): Remittance
    {
        $all = [...array_map(fn (string $a): RemittanceLine => $this->line('ZZTEST RESTO', $a), $amounts), ...$lines];

        return Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable($period), 'Recibos 2026', 'recibos.xlsx', $all, null);
    }

    private function line(string $name, string $amount): RemittanceLine
    {
        $line = (new RemittanceLine())->setDebtorName($name)->setAmount($amount);
        (new \ReflectionProperty($line, 'id'))->setValue($line, $this->nextId++);

        return $line;
    }

    private function entry(string $date, string $amount, string $concept): AccountEntry
    {
        $entry = (new AccountEntry())->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount);
        (new \ReflectionProperty($entry, 'id'))->setValue($entry, $this->nextId++);

        return $entry;
    }
}
