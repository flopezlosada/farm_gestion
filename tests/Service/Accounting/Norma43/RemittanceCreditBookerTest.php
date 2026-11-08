<?php

namespace App\Tests\Service\Accounting\Norma43;

use App\Entity\AccountantDelivery;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Repository\BudgetCategoryRepository;
use App\Repository\RemittanceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\Norma43\Norma43Movement;
use App\Service\Accounting\Norma43\RemittanceCreditBooker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * El abono de una remesa se anota con la partida de lo que cobra la remesa, no por su
 * concepto. Las cifras son las de Fiare en octubre de 2026: la remesa de cuotas pide
 * 7.626,40 y el día 13 entran dos abonos, 7.238,40 y 125,00. Sin base de datos.
 */
class RemittanceCreditBookerTest extends TestCase
{
    private int $nextId = 1;

    private FinancialAccount $bank;

    private ?AccountantDelivery $closedBy = null;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->bank = (new FinancialAccount())->setName('Fiare')->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2026-01-01'));
        $this->setId($this->bank);
    }

    /**
     * El abono mayor del día de la remesa va a «Cuotas de socixs», marcado y ligado. El
     * pequeño del mismo día (¿inscripciones?) se queda para una persona.
     */
    public function testElAbonoMayorDeLaRemesaVaACuotas(): void
    {
        $fees = $this->category('Cuotas de socixs');
        $main = $this->line('2026-10-13', 723840, 'AB.REM.20260020180');
        $small = $this->line('2026-10-13', 12500, 'AB.REM.20260020179');

        $booked = $this->booker($fees, [$this->remittance('2026-10-01', '7626.40')])->bookSafe([$small, $main], null);

        $this->assertCount(1, $booked);
        $this->assertSame($booked[0], $main->getEntry());
        $this->assertSame($fees, $booked[0]->getCategory());
        $this->assertSame('7238.40', $booked[0]->getAmount());
        $this->assertTrue($booked[0]->isAutoBooked());
        $this->assertNull($small->getEntry());
    }

    /** Un abono que pasa de lo pedido no es esa remesa: no se anota. */
    public function testUnAbonoMayorQueLoPedidoNoSeAnota(): void
    {
        $line = $this->line('2026-10-13', 800000, 'AB.REM.20260020180');

        $this->assertSame([], $this->booker($this->category('Cuotas de socixs'), [$this->remittance('2026-10-01', '7626.40')])->bookSafe([$line], null));
    }

    /** Sin remesa importada de ese mes, el abono no se sabe de qué es. */
    public function testSinRemesaNoSeAnota(): void
    {
        $line = $this->line('2026-11-13', 723840, 'AB.REM.20260020180');

        $this->assertSame([], $this->booker($this->category('Cuotas de socixs'), [$this->remittance('2026-10-01', '7626.40')])->bookSafe([$line], null));
    }

    /** Lo que no es un abono de remesa no cuenta aunque sea del día. */
    public function testOtroIngresoNoEsUnLote(): void
    {
        $line = $this->line('2026-10-13', 723840, 'TRF. CUOTA');

        $this->assertSame([], $this->booker($this->category('Cuotas de socixs'), [$this->remittance('2026-10-01', '7626.40')])->bookSafe([$line], null));
    }

    /** En un trimestre entregado no se anota nada. */
    public function testEnUnTrimestreEntregadoNoSeAnota(): void
    {
        $this->closedBy = new AccountantDelivery(InvoiceQuarter::of(2026, 4), null, new \DateTimeImmutable('2027-01-04'));
        $line = $this->line('2026-10-13', 723840, 'AB.REM.20260020180');

        $this->assertSame([], $this->booker($this->category('Cuotas de socixs'), [$this->remittance('2026-10-01', '7626.40')])->bookSafe([$line], null));
    }

    /**
     * Un cobro suelto anterior a la remesa (97,00 el 4 de agosto) es lo único que se ve
     * si el extracto acaba antes de que entre: no es la remesa y no se anota.
     */
    public function testUnCobroSueltoAnteriorALaRemesaNoSeAnota(): void
    {
        $line = $this->line('2026-08-04', 9700, 'AB.REM.20260020101');

        $this->assertSame([], $this->booker($this->category('Cuotas de socixs'), [$this->remittance('2026-08-01', '7472.40')])->bookSafe([$line], null));
        $this->assertNull($line->getEntry());
    }

    /**
     * Dos remesas del mismo mes, la de cuotas de cesta y la de cuota de socix: cada abono
     * va a la partida de la suya, aunque las dos busquen en las mismas fechas.
     */
    public function testDosRemesasDelMismoMesVaCadaAbonoASuPartida(): void
    {
        $fees = $this->category('Cuotas de socixs');
        $membership = $this->category('Cuota de mantenimiento');
        $basketLot = $this->line('2026-10-13', 723840, 'AB.REM.20260020180');
        $membershipLot = $this->line('2026-10-20', 58000, 'AB.REM.20260020190');
        $remittances = [
            $this->remittance('2026-10-01', '600.00', Remittance::KIND_MEMBERSHIP),
            $this->remittance('2026-10-01', '7626.40'),
        ];

        $booked = $this->booker([$fees, $membership], $remittances)->bookSafe([$basketLot, $membershipLot], null);

        $this->assertCount(2, $booked);
        $this->assertSame($fees, $basketLot->getEntry()?->getCategory());
        $this->assertSame($membership, $membershipLot->getEntry()?->getCategory());
    }

    /**
     * @param BudgetCategory|list<BudgetCategory> $categories Las partidas que existen.
     * @param list<Remittance>                    $remittances
     */
    private function booker(BudgetCategory|array $categories, array $remittances): RemittanceCreditBooker
    {
        $byName = [];
        foreach (\is_array($categories) ? $categories : [$categories] as $category) {
            $byName[$category->getName()] = $category;
        }
        $repo = $this->createStub(RemittanceRepository::class);
        $repo->method('findAllNewestFirst')->willReturn($remittances);
        $categoryRepo = $this->createStub(BudgetCategoryRepository::class);
        $categoryRepo->method('findOneByGroupAndName')->willReturnCallback(static fn (string $g, string $n): ?BudgetCategory => $byName[$n] ?? null);
        $closed = $this->createStub(ClosedPeriods::class);
        $closed->method('closingDeliveryFor')->willReturnCallback(fn (): ?AccountantDelivery => $this->closedBy);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });

        return new RemittanceCreditBooker($repo, $categoryRepo, $closed, $em);
    }

    private function remittance(string $period, string $total, string $kind = Remittance::KIND_BASKET): Remittance
    {
        $line = (new RemittanceLine())->setDebtorName('ZZTEST')->setAmount($total);
        $this->setId($line);

        return Remittance::fromImport($kind, new \DateTimeImmutable($period), 'Recibos', 'recibos.xlsx', [$line], null);
    }

    private function category(string $name): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName('INGRESOS')->setKind(BudgetCategoryGroup::KIND_INCOME);
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

    private function setId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);
    }
}
