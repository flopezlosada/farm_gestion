<?php

namespace App\Tests\EventListener;

use App\Entity\AccountantDelivery;
use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\Customer;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use App\Service\Accounting\Closing\ClosedPeriodException;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Con un trimestre entregado a la gestoría, nada fechado en él se crea, se cambia
 * ni se borra; lo de otros trimestres sigue igual, y reabrir lo desbloquea. Cobrar
 * después una emitida del trimestre sí se puede: no cambia lo entregado. Uno que
 * aún no ha terminado no se entrega.
 *
 * Autocontenido: el 3T2094, con el reloj ya en el 4T2094.
 */
class ClosedPeriodGuardTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private ClosedPeriods $closed;
    private InvoiceQuarter $quarter;
    private FinancialAccount $account;
    private BudgetCategory $category;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2094-10-10 10:00');
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->closed = static::getContainer()->get(ClosedPeriods::class);
        $this->quarter = InvoiceQuarter::of(2094, 3);

        $this->account = (new FinancialAccount())->setName('Banco cierre '.bin2hex(random_bytes(3)))
            ->setKind(FinancialAccount::KIND_BANK)->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2094-01-01'))
            ->setActive(true)->setSortOrder(99);
        $group = (new BudgetCategoryGroup())->setName('GASTOS CIERRE '.bin2hex(random_bytes(3)))
            ->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $this->category = (new BudgetCategory())->setGroup($group)->setName('Varios')->setSortOrder(1);
        $this->persist($this->account, $group, $this->category);
    }

    protected function tearDown(): void
    {
        // Primero las entregas: con el trimestre cerrado, la red no dejaría borrar lo demás.
        $this->em->clear();
        foreach ($this->em->getRepository(AccountantDelivery::class)->findBy(['year' => 2094]) as $delivery) {
            $this->em->remove($delivery);
        }
        $this->em->flush();
        $this->closed->reset();

        foreach (array_reverse($this->created) as [$class, $id]) {
            if (($row = $this->em->find($class, $id)) !== null) {
                $this->em->remove($row);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testUnApunteNuevoEnUnTrimestreEntregadoNoEntra(): void
    {
        $this->closed->deliver($this->quarter, null);

        $this->expectException(ClosedPeriodException::class);
        $this->expectExceptionMessage('El 3T2094 ya se entregó a la gestoría');
        $this->persist($this->entry('2094-08-01'));
    }

    public function testFueraDelTrimestreSeSigueAnotando(): void
    {
        $this->closed->deliver($this->quarter, null);

        $entry = $this->entry('2094-10-01');
        $this->persist($entry);

        $this->assertNotNull($entry->getId());
    }

    /** Sacar un apunte del trimestre también lo cambia: cuenta la fecha de antes. */
    public function testNoSeSacaUnApunteDelTrimestreEntregado(): void
    {
        $entry = $this->entry('2094-09-30');
        $this->persist($entry);
        $this->closed->deliver($this->quarter, null);

        $entry->setDate(new \DateTimeImmutable('2094-10-01'));
        $this->expectException(ClosedPeriodException::class);
        $this->em->flush();
    }

    public function testNoSeBorraUnApunteDelTrimestreEntregado(): void
    {
        $entry = $this->entry('2094-07-15');
        $this->persist($entry);
        $this->closed->deliver($this->quarter, null);

        $this->em->remove($entry);
        $this->expectException(ClosedPeriodException::class);
        $this->em->flush();
    }

    /** Reabierto, se corrige; y queda escrito que se reabrió. */
    public function testReabiertoSeCorrige(): void
    {
        $entry = $this->entry('2094-07-15');
        $this->persist($entry);
        $this->closed->deliver($this->quarter, null);
        $this->closed->reopen($this->quarter, null);

        $entry->setConcept('Corregido');
        $this->em->flush();

        $this->assertFalse($this->closed->latestOf($this->quarter)->isStanding());
        $this->assertNull($this->closed->closingDeliveryFor(new \DateTimeImmutable('2094-07-15')));
    }

    /** Cobrar en octubre una emitida de septiembre no cambia lo entregado. */
    public function testCobrarUnaEmitidaDelTrimestreSiSePuede(): void
    {
        $customer = (new Customer())->setName('Cliente cierre')->setTaxId('Q2894000H');
        $invoice = (new IssuedInvoice(IssuedInvoice::SERIES_ORDINARY))
            ->setCustomer($customer)
            ->setIssueDate(new \DateTimeImmutable('2094-09-20'))
            ->addLine(new IssuedInvoiceLine('Formación', '100.00', '21'));
        $invoice->issue(1, null, new \DateTimeImmutable());
        $this->persist($customer, $invoice);
        $this->closed->deliver($this->quarter, null);

        $payment = $this->entry('2094-10-05', '121.00');
        $this->persist($payment);
        $invoice->markPaid($payment);
        $this->em->flush();

        $this->assertTrue($invoice->isPaid());
    }

    /**
     * El último día del trimestre aún no se entrega: cerraría también lo que quede por
     * anotar ese día. Al día siguiente, sí.
     */
    public function testUnTrimestreSinTerminarNoSeEntrega(): void
    {
        static::mockTime('2094-09-30 20:00');
        $this->assertFalse($this->closed->canDeliver($this->quarter));
        try {
            $this->closed->deliver($this->quarter, null);
            $this->fail('Un trimestre en curso no debería poder entregarse.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('01/10/2094', $e->getMessage());
        }
        $this->assertNull($this->closed->latestOf($this->quarter));

        static::mockTime('2094-10-01 00:00');
        $this->assertTrue($this->closed->deliver($this->quarter, null)->isStanding());
    }

    private function entry(string $date, string $amount = '-50.00'): AccountEntry
    {
        return (new AccountEntry())->setAccount($this->account)->setCategory($this->category)
            ->setDate(new \DateTimeImmutable($date))->setConcept('Prueba de cierre')->setAmount($amount);
    }

    private function persist(object ...$rows): void
    {
        foreach ($rows as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();
        foreach ($rows as $row) {
            $this->created[] = [$row::class, $row->getId()];
        }
    }
}
