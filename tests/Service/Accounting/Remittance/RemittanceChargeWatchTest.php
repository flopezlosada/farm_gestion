<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\AccountEntry;
use App\Entity\FinancialAccount;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Repository\AccountEntryRepository;
use App\Repository\RemittanceRepository;
use App\Service\Accounting\Remittance\RemittanceChargeWatch;
use PHPUnit\Framework\TestCase;

/**
 * Si una remesa enviada ha entrado en el banco, por su día de cargo y los abonos
 * `ab.rem.<ref>` del libro, como los pone Fiare. Importes y referencias inventados.
 */
class RemittanceChargeWatchTest extends TestCase
{
    private int $nextId = 1;

    /**
     * Pedida para un sábado, se carga el lunes 19: el abono puede llegar del jueves 15
     * al miércoles 21. Sin él, se avisa sólo cuando el libro pasa del 21.
     */
    public function testCargoPedidoEnSabado(): void
    {
        $remittance = $this->sent('2026-10-17');

        $this->assertSame([], RemittanceChargeWatch::findUncredited([$remittance], [$this->credit('2026-10-19')], new \DateTimeImmutable('2026-10-30')));
        $this->assertSame([], RemittanceChargeWatch::findUncredited([$remittance], [$this->credit('2026-10-15')], new \DateTimeImmutable('2026-10-30')), 'dos hábiles antes del lunes es el jueves');
        $this->assertSame([], RemittanceChargeWatch::findUncredited([$remittance], [], new \DateTimeImmutable('2026-10-21')), 'el libro aún no pasa del margen');
        $this->assertSame([$remittance], RemittanceChargeWatch::findUncredited([$remittance], [], new \DateTimeImmutable('2026-10-22')));
        $this->assertSame([$remittance], RemittanceChargeWatch::findUncredited([$remittance], [$this->credit('2026-10-14')], new \DateTimeImmutable('2026-10-22')), 'tres hábiles antes ya es otro lote');
    }

    /**
     * Pedida para Viernes Santo, se carga el martes (el Lunes de Pascua también cierra):
     * el margen va del miércoles 1 al jueves 9 de abril.
     */
    public function testCargoPedidoEnViernesSanto(): void
    {
        $remittance = $this->sent('2026-04-03');

        $this->assertSame([], RemittanceChargeWatch::findUncredited([$remittance], [$this->credit('2026-04-01')], new \DateTimeImmutable('2026-04-30')));
        $this->assertSame([$remittance], RemittanceChargeWatch::findUncredited([$remittance], [$this->credit('2026-03-31')], new \DateTimeImmutable('2026-04-10')));
        $this->assertSame([], RemittanceChargeWatch::findUncredited([$remittance], [], new \DateTimeImmutable('2026-04-09')), 'el libro llega justo al final del margen');
    }

    /** Fiare a veces abona el día hábil anterior al pedido: cuenta como cobrada. */
    public function testElAbonoDelDiaAnteriorCuentaComoCobrada(): void
    {
        $this->assertSame([], RemittanceChargeWatch::findUncredited([$this->sent('2026-11-18')], [$this->credit('2026-11-17')], new \DateTimeImmutable('2026-12-31')));
    }

    /**
     * Lo que cuenta es el día de cargo, no el mes que se cobra: la de septiembre
     * cargada el 1 de octubre entra con el abono de octubre, y uno de septiembre no la
     * da por cobrada.
     */
    public function testElAbonoEnElMesSiguienteAlDeLaRemesa(): void
    {
        $september = $this->sent('2026-10-01', period: '2026-09-01');

        $this->assertSame([], RemittanceChargeWatch::findUncredited([$september], [$this->credit('2026-10-01')], new \DateTimeImmutable('2026-10-31')));
        $this->assertSame([$september], RemittanceChargeWatch::findUncredited([$september], [$this->credit('2026-09-18')], new \DateTimeImmutable('2026-10-31')));
    }

    /**
     * Sin día de cargo (importada, o se le quitó) no se vigila; un borrador, tampoco:
     * no se ha enviado.
     */
    public function testSinDiaDeCargoOEnBorradorNoSeVigila(): void
    {
        $imported = Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-08-01'), 'Recibos AGOSTO 2026', 'agosto.xlsx', [$this->line('120.00')], null);
        $cleared = $this->sent('2026-09-18');
        $cleared->rescheduleCharge(null);
        $draft = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-10-01'), 'Recibos OCTUBRE 2026', Remittance::SOURCE_APP, null);
        $draft->addLine($this->line('120.00'));

        $this->assertSame([], RemittanceChargeWatch::findUncredited([$imported, $cleared, $draft], [], new \DateTimeImmutable('2026-12-31')));
    }

    /**
     * Dos abonos de remesa en el mes: el pequeño (un recibo devuelto que se volvió a
     * cobrar suelto) días antes, y el de la remesa en su día. Con el grande entra; con
     * el pequeño solo, fuera del margen, no.
     */
    public function testDosAbonosEnElMesUnoPequeno(): void
    {
        $remittance = $this->sent('2026-11-18');
        $loose = $this->credit('2026-11-05', '55.00');

        $this->assertSame([], RemittanceChargeWatch::findUncredited([$remittance], [$loose, $this->credit('2026-11-18', '6842.50')], new \DateTimeImmutable('2026-11-30')));
        $this->assertSame([$remittance], RemittanceChargeWatch::findUncredited([$remittance], [$loose], new \DateTimeImmutable('2026-11-30')));
    }

    /** Sólo cuentan los abonos de remesa: ni las devoluciones, ni un concepto que sólo los menciona. */
    public function testSoloCuentanLosAbonosDeRemesa(): void
    {
        $remittance = $this->sent('2026-11-18');
        $others = [
            $this->credit('2026-11-18', '-55.00', 'bel-liq.rem.devol20960001234'),
            $this->credit('2026-11-18', '120.00', 'transferencia ab.rem.20960001234'),
            $this->credit('2026-11-18', '120.00', 'ab.rem.'),
            (new AccountEntry())->setConcept('ab.rem.20960001299')->setAmount('6842.50'),
        ];

        $this->assertSame([$remittance], RemittanceChargeWatch::findUncredited([$remittance], $others, new \DateTimeImmutable('2026-11-30')));
    }

    /**
     * El día habitual es la mediana de los últimos abonos: ene-ago entre el 18 y el
     * 21, y luego un 9 y un 13 no lo mueven. Sin abonos de remesa, no hay.
     */
    public function testElDiaHabitual(): void
    {
        $credits = array_map(fn (string $d): AccountEntry => $this->credit($d), ['2026-10-13', '2026-09-09', '2026-08-18', '2026-07-21', '2026-06-19', '2026-05-20']);

        $this->assertSame(18, RemittanceChargeWatch::usualDay($credits));
        $this->assertSame(19, RemittanceChargeWatch::usualDay([$this->credit('2026-06-19')]));
        $this->assertNull(RemittanceChargeWatch::usualDay([]));
        $this->assertNull(RemittanceChargeWatch::usualDay([$this->credit('2026-06-19', '-55.00', 'bel-liq.rem.devol1')]));
    }

    /**
     * El día propuesto es el siguiente a hoy que cae en el día habitual: este mes si no
     * ha pasado, si no el siguiente; en día hábil y, en un mes corto, su último día.
     */
    public function testElDiaDeCargoPropuesto(): void
    {
        $this->assertSame('2026-11-18', RemittanceChargeWatch::nextChargeDate(18, new \DateTimeImmutable('2026-10-31'))->format('Y-m-d'));
        $this->assertSame('2026-11-18', RemittanceChargeWatch::nextChargeDate(18, new \DateTimeImmutable('2026-11-10 09:30'))->format('Y-m-d'));
        $this->assertSame('2026-12-18', RemittanceChargeWatch::nextChargeDate(18, new \DateTimeImmutable('2026-11-18'))->format('Y-m-d'), 'para hoy ya no se puede pedir');
        $this->assertSame('2026-11-30', RemittanceChargeWatch::nextChargeDate(31, new \DateTimeImmutable('2026-11-05'))->format('Y-m-d'));
        $this->assertSame('2026-10-19', RemittanceChargeWatch::nextChargeDate(17, new \DateTimeImmutable('2026-10-01'))->format('Y-m-d'), 'el 17 es sábado');
    }

    /**
     * Con la base: las remesas vigiladas, la cuenta donde entran (la del último abono
     * de remesa) y hasta dónde llega su libro. El libro de otra cuenta, más al día, no
     * cuenta; los abonos se buscan en el margen de todas a la vez.
     */
    public function testLasQueNoHanEntradoConLaBase(): void
    {
        $fiare = $this->account('Fiare');
        $other = $this->account('La otra');
        $october = $this->sent('2026-10-19');
        $november = $this->sent('2026-11-18');
        $octoberCredit = $this->credit('2026-10-19', '6842.50', 'ab.rem.20960001234', $fiare);

        $remittances = $this->createStub(RemittanceRepository::class);
        $remittances->method('findSentWithChargeDate')->willReturn([$october, $november]);
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('findLatestByConceptPrefix')->willReturn([$octoberCredit]);
        $entries->method('lastDateByAccount')->willReturn([$fiare->getId() => new \DateTimeImmutable('2026-11-24'), $other->getId() => new \DateTimeImmutable('2026-12-31')]);
        $entries->expects($this->once())->method('findByConceptPrefix')
            ->with('ab.rem.', new \DateTimeImmutable('2026-10-15'), new \DateTimeImmutable('2026-11-20'))
            ->willReturn([$octoberCredit]);

        $found = (new RemittanceChargeWatch($remittances, $entries))->uncredited();

        $this->assertCount(1, $found);
        $this->assertSame($november, $found[0]['remittance']);
        $this->assertSame('2026-11-24', $found[0]['bookUpTo']->format('Y-m-d'));
    }

    /** Sin ningún abono de remesa en el libro no se sabe en qué cuenta entran: no se avisa. */
    public function testSinAbonosDeRemesaNoSeAvisa(): void
    {
        $remittances = $this->createStub(RemittanceRepository::class);
        $remittances->method('findSentWithChargeDate')->willReturn([$this->sent('2026-10-19')]);
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('findLatestByConceptPrefix')->willReturn([]);
        $entries->method('lastDateByAccount')->willReturn([1 => new \DateTimeImmutable('2026-12-31')]);
        $entries->expects($this->never())->method('findByConceptPrefix');

        $watch = new RemittanceChargeWatch($remittances, $entries);

        $this->assertSame([], $watch->uncredited());
        $this->assertNull($watch->usualChargeDate(new \DateTimeImmutable('2026-10-31')));
    }

    /** El día propuesto sale de los últimos abonos del libro. */
    public function testElDiaPropuestoConLaBase(): void
    {
        $entries = $this->createStub(AccountEntryRepository::class);
        $entries->method('findLatestByConceptPrefix')->willReturn([$this->credit('2026-10-13'), $this->credit('2026-09-09'), $this->credit('2026-08-18'), $this->credit('2026-07-21')]);

        $watch = new RemittanceChargeWatch($this->createStub(RemittanceRepository::class), $entries);

        $this->assertSame('2026-11-13', $watch->usualChargeDate(new \DateTimeImmutable('2026-10-31'))?->format('Y-m-d'));
    }

    /** Una remesa preparada aquí y enviada con ese día de cargo. */
    private function sent(string $chargeDate, string $period = '2026-10-01'): Remittance
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable($period), 'Recibos', Remittance::SOURCE_APP, null);
        $remittance->addLine($this->line('6842.50'));
        $remittance->markSent(null, new \DateTimeImmutable($chargeDate));

        return $remittance;
    }

    private function line(string $amount): RemittanceLine
    {
        return (new RemittanceLine())
            ->setDebtorName('ZZTEST ANA')
            ->setIban('ES91 2100 0418 4502 0005 1332')
            ->setMandateReference('CUOTA MENSUAL 900')
            ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'))
            ->setDebitReference('CUOTA MENSUAL 900 ' . $this->nextId++)
            ->setSequenceType('RCUR')
            ->setAmount($amount);
    }

    /** Un apunte del libro; por omisión, el abono de una remesa. */
    private function credit(string $date, string $amount = '6842.50', string $concept = 'ab.rem.20960001234', ?FinancialAccount $account = null): AccountEntry
    {
        $entry = (new AccountEntry())->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount)->setAccount($account);
        (new \ReflectionProperty(AccountEntry::class, 'id'))->setValue($entry, $this->nextId++);

        return $entry;
    }

    private function account(string $name): FinancialAccount
    {
        $account = (new FinancialAccount())->setName($name)->setKind(FinancialAccount::KIND_BANK);
        (new \ReflectionProperty(FinancialAccount::class, 'id'))->setValue($account, $this->nextId++);

        return $account;
    }
}
