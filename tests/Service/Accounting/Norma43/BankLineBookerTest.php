<?php

namespace App\Tests\Service\Accounting\Norma43;

use App\Entity\AccountantDelivery;
use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Entity\User;
use App\Repository\AccountEntryRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\Norma43\BankLineBooker;
use App\Service\Accounting\Norma43\Norma43Movement;
use App\Service\Accounting\Suggestion\CategorySuggester;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * La app anota sola los movimientos del banco de los que no hay duda: la partida de
 * las últimas veces (al menos dos, todas la misma) o la de la única factura que espera
 * ese pago. Lo demás se queda para una persona. Sin base de datos.
 */
class BankLineBookerTest extends TestCase
{
    private int $nextId = 1;

    private FinancialAccount $bank;

    /** @var list<AccountEntry> */
    private array $history = [];

    /** @var list<ReceivedInvoice> */
    private array $awaiting = [];

    /** @var list<AccountEntry> */
    private array $splits = [];

    /** @var list<AccountEntry> */
    private array $parts = [];

    /** @var list<object> */
    private array $persisted = [];

    private ?AccountantDelivery $closedBy = null;

    protected function setUp(): void
    {
        $this->bank = $this->account();
    }

    /**
     * El recibo de la gestoría, que las dos últimas veces fue a «Gestoría»: se anota
     * solo con esa partida y ese proveedor, marcado y ligado al movimiento.
     */
    public function testLoQueSeRepiteConLaMismaPartidaSeAnotaSolo(): void
    {
        $advisory = $this->category('Gestoría');
        $provider = (new Provider())->setName('Asesoría Caymuz');
        $this->setId($provider);
        $this->history = [
            $this->entry('2026-09-05', '-170.00', 'rcbo.caymuz, s.l.', $advisory)->setProvider($provider),
            $this->entry('2026-08-05', '-170.00', 'rcbo.caymuz, s.l.', $advisory)->setProvider($provider),
        ];
        $line = $this->line('2026-10-05', -17000, 'RCBO.CAYMUZ, S.L.');
        $treasurer = new User();

        $booked = $this->booker()->bookSafe([$line], $treasurer);

        $this->assertCount(1, $booked);
        $entry = $booked[0];
        $this->assertSame($entry, $line->getEntry());
        $this->assertSame($advisory, $entry->getCategory());
        $this->assertSame($provider, $entry->getProvider());
        $this->assertSame('-170.00', $entry->getAmount());
        $this->assertSame('2026-10-05', $entry->getDate()?->format('Y-m-d'));
        $this->assertTrue($entry->isAutoBooked());
        $this->assertSame($treasurer, $entry->getCreatedBy());
        $this->assertSame([$entry], $this->persisted);
    }

    /** Una sola vez anterior puede ser casualidad: se queda para una persona. */
    public function testConUnaSolaVezNoSeAnota(): void
    {
        $this->history = [$this->entry('2026-09-05', '-170.00', 'rcbo.caymuz, s.l.', $this->category('Gestoría'))];
        $line = $this->line('2026-10-05', -17000, 'RCBO.CAYMUZ, S.L.');

        $this->assertSame([], $this->booker()->bookSafe([$line], null));
        $this->assertNull($line->getEntry());
    }

    /** Si las últimas veces no coinciden, tampoco. */
    public function testSiLasUltimasVecesNoCoincidenNoSeAnota(): void
    {
        $this->history = [
            $this->entry('2026-09-05', '-170.00', 'trf. a socix', $this->category('Nóminas')),
            $this->entry('2026-08-05', '-170.00', 'trf. a socix', $this->category('Semillas')),
        ];

        $this->assertSame([], $this->booker()->bookSafe([$this->line('2026-10-05', -17000, 'TRF. A SOCIX')], null));
    }

    /** Un traspaso necesita la otra mitad: no se anota solo aunque se repita. */
    public function testUnTraspasoNoSeAnotaSolo(): void
    {
        $transfer = $this->category('Traspaso entre cuentas', BudgetCategoryGroup::KIND_TRANSFER);
        $this->history = [
            $this->entry('2026-09-05', '-246.90', 'tarjeta tj mixta person', $transfer),
            $this->entry('2026-08-05', '-246.90', 'tarjeta tj mixta person', $transfer),
        ];

        $this->assertSame([], $this->booker()->bookSafe([$this->line('2026-10-05', -24690, 'TARJETA TJ MIXTA PERSON')], null));
    }

    /**
     * La única factura aceptada que espera ese importe: se anota con su partida aunque
     * el concepto no se haya visto nunca, y la factura queda pagada por ese apunte.
     */
    public function testLaUnicaFacturaQueEsperaElPagoSeAnotaYSeEngancha(): void
    {
        $seeds = $this->category('Semillas');
        $invoice = $this->awaiting('265.53', $seeds);
        $this->awaiting = [$invoice];
        $line = $this->line('2026-10-08', -26553, 'TRF. SEMILLEROS ALVAREZ');

        $booked = $this->booker()->bookSafe([$line], null);

        $this->assertCount(1, $booked);
        $this->assertSame($seeds, $booked[0]->getCategory());
        $this->assertSame($booked[0], $invoice->getAccountEntry());
        $this->assertSame('F-1', $booked[0]->getInvoiceNumber());
    }

    /**
     * Dos facturas esperan el mismo importe: elegir es de una persona. Ni siquiera se
     * anota con la partida de otras veces, que dejaría a la buena esperando siempre.
     */
    public function testDosFacturasDelMismoImporteNoSeAnota(): void
    {
        $seeds = $this->category('Semillas');
        $this->awaiting = [$this->awaiting('265.53', $seeds), $this->awaiting('265.53', $seeds)];
        $this->history = [
            $this->entry('2026-09-08', '-12.00', 'trf. semilleros alvarez', $seeds),
            $this->entry('2026-08-08', '-30.00', 'trf. semilleros alvarez', $seeds),
        ];

        $this->assertSame([], $this->booker()->bookSafe([$this->line('2026-10-08', -26553, 'TRF. SEMILLEROS ALVAREZ')], null));
    }

    /** Una factura paga un movimiento: dos del mismo importe no se la llevan los dos. */
    public function testUnaFacturaPagaUnSoloMovimiento(): void
    {
        $seeds = $this->category('Semillas');
        $invoice = $this->awaiting('265.53', $seeds);
        $this->awaiting = [$invoice];
        $first = $this->line('2026-10-08', -26553, 'TRF. SEMILLEROS ALVAREZ');
        $second = $this->line('2026-10-20', -26553, 'TRF. OTRO PROVEEDOR');

        $booked = $this->booker()->bookSafe([$first, $second], null);

        $this->assertCount(1, $booked);
        $this->assertSame($first->getEntry(), $invoice->getAccountEntry());
        $this->assertNull($second->getEntry());
    }

    /** En un trimestre entregado a la gestoría no se anota nada. */
    public function testEnUnTrimestreEntregadoNoSeAnota(): void
    {
        $advisory = $this->category('Gestoría');
        $this->history = [
            $this->entry('2026-09-05', '-170.00', 'rcbo.caymuz, s.l.', $advisory),
            $this->entry('2026-08-05', '-170.00', 'rcbo.caymuz, s.l.', $advisory),
        ];
        $this->closedBy = new AccountantDelivery(InvoiceQuarter::of(2026, 4), null, new \DateTimeImmutable('2027-01-04'));

        $this->assertSame([], $this->booker()->bookSafe([$this->line('2026-10-05', -17000, 'RCBO.CAYMUZ, S.L.')], null));
    }

    /**
     * El cargo del Ayuntamiento, que la última vez se partió en alquiler y suministros
     * con el mismo total: se anota y se parte igual, las dos mitades marcadas.
     */
    public function testElCargoQueSePartioLaUltimaVezSeParteIgual(): void
    {
        $rent = $this->category('Local');
        $supplies = $this->category('Suministros');
        $previous = $this->entry('2026-09-09', '-158.95', 'trf. ayuntamiento de torremocha', $rent);
        $part = $previous->splitOff('50.00', $supplies, 'Suministros del local');
        $this->setId($part);
        $this->history = [$previous, $this->entry('2026-08-09', '-158.95', 'trf. ayuntamiento de torremocha', $rent)];
        $this->splits = [$previous];
        $this->parts = [$part];
        $line = $this->line('2026-10-09', -15895, 'TRF. AYUNTAMIENTO DE TORREMOCHA');

        $booked = $this->booker()->bookSafe([$line], null);

        $this->assertCount(1, $booked);
        $this->assertSame('-108.95', $booked[0]->getAmount());
        $this->assertSame('-158.95', $booked[0]->getMovementAmount());
        $this->assertCount(2, $this->persisted);
        $this->assertSame($supplies, $this->persisted[1]->getCategory());
        $this->assertTrue($this->persisted[1]->isAutoBooked());
    }

    private function booker(): BankLineBooker
    {
        $entries = $this->createStub(AccountEntryRepository::class);
        $entries->method('findCategorizedSince')->willReturnCallback(fn (): array => $this->history);
        $entries->method('findSplitBetween')->willReturnCallback(fn (): array => $this->splits);
        $entries->method('findPartsOf')->willReturnCallback(fn (): array => $this->parts);
        $invoices = $this->createStub(ReceivedInvoiceRepository::class);
        $invoices->method('findAwaitingPayment')->willReturnCallback(fn (): array => $this->awaiting);
        $closed = $this->createStub(ClosedPeriods::class);
        $closed->method('closingDeliveryFor')->willReturnCallback(fn (): ?AccountantDelivery => $this->closedBy);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });

        return new BankLineBooker(new CategorySuggester($entries), $invoices, $entries, $closed, $em);
    }

    private function account(): FinancialAccount
    {
        $account = (new FinancialAccount())->setName('Fiare')->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2026-01-01'));
        $this->setId($account);

        return $account;
    }

    private function category(string $name, int $kind = BudgetCategoryGroup::KIND_EXPENSE): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName('Grupo '.$name)->setKind($kind);
        $category = (new BudgetCategory())->setGroup($group)->setName($name);
        $this->setId($category);

        return $category;
    }

    private function line(string $date, int $cents, string $text): BankStatementLine
    {
        $day = new \DateTimeImmutable($date);
        $line = new BankStatementLine($this->bank, new Norma43Movement($day, $day, '99', '000', $cents, sprintf('%010d', $this->nextId), '', '', [$text]), 1);
        $this->setId($line);

        return $line;
    }

    private function entry(string $date, string $amount, string $concept, BudgetCategory $category): AccountEntry
    {
        $entry = (new AccountEntry())->setAccount($this->bank)->setDate(new \DateTimeImmutable($date))
            ->setAmount($amount)->setConcept($concept)->setCategory($category);
        $this->setId($entry);

        return $entry;
    }

    private function awaiting(string $total, BudgetCategory $category): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice('f.pdf', 'f.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->markRead(ExtractedInvoice::fromArray(['fecha' => '2026-10-01', 'total' => (float) $total, 'proveedor' => 'Semilleros Álvarez']), 'test', null, new \DateTimeImmutable());
        $invoice->acceptUnpaid($category, $total, null, 'Semilleros Álvarez', 'F-1');

        return $invoice;
    }

    private function setId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);
    }
}
