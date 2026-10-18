<?php

namespace App\Tests\Service\Accounting\Agenda;

use App\Entity\AccountantDelivery;
use App\Entity\AccountEntry;
use App\Entity\Customer;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use App\Entity\ReceivedInvoice;
use App\Entity\Remittance;
use App\Repository\AccountEntryRepository;
use App\Repository\FinancialAccountRepository;
use App\Repository\IssuedInvoiceRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Repository\RemittanceRepository;
use App\Service\Accounting\Agenda\AccountingAgenda;
use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Agenda\AgendaUrgency;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\AccountantPackage;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceBook;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * La lista de «Por hacer» de la portada de contabilidad, sin base de datos: cada
 * repositorio devuelve lo que pondría la base en ese caso.
 */
class AccountingAgendaTest extends TestCase
{
    private int $nextId = 1;

    /** Nada pendiente (el trimestre, entregado): la lista sale vacía y la portada dice «Todo al día». */
    public function testSinNadaPendienteNoHayTareas(): void
    {
        $this->assertSame([], $this->agenda()->items(new \DateTimeImmutable('2026-11-15')));
    }

    /**
     * Del 1 al 20 del mes siguiente al trimestre toca la gestoría; en la última semana
     * aprieta, y pasado el plazo sin entregar sigue apretando.
     */
    public function testLaGestoriaHastaQueConsteEntregado(): void
    {
        $agenda = $this->agenda(deliveries: []);

        $early = $this->only($agenda->items(new \DateTimeImmutable('2026-10-01')));
        $this->assertSame('Mandar el 3T2026 a la gestoría: plazo hasta el 20 de octubre', $early->title);
        $this->assertSame(AgendaUrgency::Normal, $early->urgency);
        $this->assertSame(['year' => 2026, 'quarter' => 3], $early->routeParams);

        $this->assertSame(AgendaUrgency::Soon, $this->only($agenda->items(new \DateTimeImmutable('2026-10-16')))->urgency);
        $this->assertSame(AgendaUrgency::Soon, $this->only($agenda->items(new \DateTimeImmutable('2026-10-20')))->urgency, 'el último día aún vale');

        $late = $this->only($agenda->items(new \DateTimeImmutable('2026-11-15')));
        $this->assertSame('Mandar el 3T2026 a la gestoría: el plazo acabó el 20 de octubre', $late->title);
        $this->assertSame(AgendaUrgency::Soon, $late->urgency);
    }

    /** Entregado, el trimestre ya no es una tarea: ni dentro del plazo ni después. */
    public function testEntregadoNoSale(): void
    {
        $agenda = $this->agenda(deliveries: [$this->delivery(2026, 3)]);

        $this->assertSame([], $agenda->items(new \DateTimeImmutable('2026-10-05')));
        $this->assertSame([], $agenda->items(new \DateTimeImmutable('2026-11-15')));
    }

    /**
     * Reabierto hay que volver a mandarlo, y aprieta aunque el plazo haya pasado o sea
     * un trimestre anterior al que toca ahora.
     */
    public function testReabiertoHayQueVolverAMandarlo(): void
    {
        $agenda = $this->agenda(deliveries: [
            $this->delivery(2026, 2, reopenedOn: '2026-10-08'),
            $this->delivery(2026, 3),
        ]);

        $item = $this->only($agenda->items(new \DateTimeImmutable('2026-11-15')));

        $this->assertSame('Volver a mandar el 2T2026 a la gestoría: se reabrió el 08/10/2026', $item->title);
        $this->assertSame(AgendaUrgency::Soon, $item->urgency);
        $this->assertSame(['year' => 2026, 'quarter' => 2], $item->routeParams);
    }

    /**
     * Reabierto el trimestre que toca ahora, sale una vez, como reabierto, y no
     * además como «mandar dentro del plazo».
     */
    public function testElTrimestreQueTocaReabiertoSaleUnaVez(): void
    {
        $item = $this->only($this->agenda(deliveries: [$this->delivery(2026, 3, reopenedOn: '2026-10-12')])
            ->items(new \DateTimeImmutable('2026-10-14')));

        $this->assertSame('Volver a mandar el 3T2026 a la gestoría: se reabrió el 12/10/2026', $item->title);
    }

    /** Los gastos del trimestre que piden factura y no la tienen, en singular y en plural. */
    public function testLaGestoriaCuentaLosGastosSinFactura(): void
    {
        $one = $this->only($this->agenda(withoutInvoice: [new AccountEntry()], deliveries: [])->items(new \DateTimeImmutable('2026-10-05')));
        $this->assertContains('1 gasto del trimestre sin factura.', $one->details);

        $many = $this->only($this->agenda(withoutInvoice: [new AccountEntry(), new AccountEntry()], deliveries: [])->items(new \DateTimeImmutable('2026-10-05')));
        $this->assertContains('2 gastos del trimestre sin factura.', $many->details);

        $none = $this->only($this->agenda(deliveries: [])->items(new \DateTimeImmutable('2026-10-05')));
        $this->assertSame(['0 facturas recibidas y 0 emitidas.'], $none->details);
    }

    /** El cuarto trimestre se presenta hasta el 30 de enero. */
    public function testElCuartoTrimestreHastaElTreintaDeEnero(): void
    {
        $item = $this->only($this->agenda()->items(new \DateTimeImmutable('2027-01-25')));

        $this->assertSame('Mandar el 4T2026 a la gestoría: plazo hasta el 30 de enero', $item->title);
    }

    /**
     * Lo que se manda, cuántas tienen avisos y qué banco tiene el libro sin llegar al
     * cierre. Una caja sin moverse o una cuenta sin ningún apunte no son atraso.
     */
    public function testLaGestoriaCuentaFacturasAvisosYLibrosAtrasados(): void
    {
        $fiare = $this->account('Fiare', FinancialAccount::KIND_BANK);
        $caixa = $this->account('La Caixa', FinancialAccount::KIND_BANK);
        $paypal = $this->account('PayPal', FinancialAccount::KIND_BANK);
        $caja = $this->account('Caja de la huerta', FinancialAccount::KIND_CASH);

        $items = $this->agenda(
            deliveries: [],
            received: [$this->received(), $this->received()],
            accounts: [$fiare, $caixa, $paypal, $caja],
            lastDates: [
                $fiare->getId() => new \DateTimeImmutable('2026-09-30'),
                $caixa->getId() => new \DateTimeImmutable('2026-08-13'),
                $caja->getId() => new \DateTimeImmutable('2026-06-25'),
            ],
        )->items(new \DateTimeImmutable('2026-10-05'));
        // La Caixa lleva además más de un mes sin meter: esa es otra tarea.
        $item = $this->only(array_values(array_filter($items, static fn (AgendaItem $i): bool => $i->route === 'accounting_accountant_export')));

        $this->assertSame([
            '2 facturas recibidas y 0 emitidas.',
            '2 con avisos que mirar antes de mandarlas.',
            'El libro de La Caixa sólo llega al 13/08/2026.',
        ], $item->details);
    }

    /**
     * Un banco con más de un mes sin apuntes: cuántos días lleva y adónde ir a meterlos.
     * A los 30 días justos todavía no es atraso.
     */
    public function testElLibroDeUnBancoSinMeter(): void
    {
        $fiare = $this->account('Fiare', FinancialAccount::KIND_BANK);
        $agenda = fn (string $last): AccountingAgenda => $this->agenda(
            accounts: [$fiare],
            lastDates: [$fiare->getId() => new \DateTimeImmutable($last)],
        );
        $today = new \DateTimeImmutable('2026-10-18');

        $item = $this->only($agenda('2026-08-31')->items($today));
        $this->assertSame('Meter los movimientos de Fiare: el libro lleva 48 días sin apuntes', $item->title);
        $this->assertSame(['El último es del 31/08/2026. Se anotan desde el extracto que se descarga de la banca online.'], $item->details);
        $this->assertSame('accounting_ledger', $item->route);
        $this->assertSame(['account' => $fiare->getId()], $item->routeParams);

        $this->assertSame([], $agenda('2026-09-18')->items($today), '30 días justos aún no es atraso');
        $this->assertCount(1, $agenda('2026-09-17')->items($today), '31 días sí');
    }

    /**
     * Ni una caja quieta, ni una cuenta sin ningún apunte, ni un apunte con fecha futura
     * (una errata) son un libro atrasado.
     */
    public function testElLibroSinMeterSoloMiraBancosConApuntesPasados(): void
    {
        $caja = $this->account('Caja de la huerta', FinancialAccount::KIND_CASH);
        $paypal = $this->account('PayPal', FinancialAccount::KIND_BANK);
        $caixa = $this->account('La Caixa', FinancialAccount::KIND_BANK);

        $this->assertSame([], $this->agenda(
            accounts: [$caja, $paypal, $caixa],
            lastDates: [
                $caja->getId() => new \DateTimeImmutable('2026-03-01'),
                $caixa->getId() => new \DateTimeImmutable('2027-03-01'),
            ],
        )->items(new \DateTimeImmutable('2026-10-18')));
    }

    /** Varios bancos atrasados: una sola tarea, con lo de cada uno, que lleva a todos los movimientos. */
    public function testElLibroDeVariosBancosSinMeter(): void
    {
        $fiare = $this->account('Fiare', FinancialAccount::KIND_BANK);
        $caixa = $this->account('La Caixa', FinancialAccount::KIND_BANK);

        $item = $this->only($this->agenda(
            accounts: [$fiare, $caixa],
            lastDates: [
                $fiare->getId() => new \DateTimeImmutable('2026-08-31'),
                $caixa->getId() => new \DateTimeImmutable('2026-08-13'),
            ],
        )->items(new \DateTimeImmutable('2026-10-18')));

        $this->assertSame('Meter los movimientos de 2 bancos: llevan más de 30 días sin apuntes', $item->title);
        $this->assertSame([
            'Fiare: el último apunte es del 31/08/2026, hace 48 días.',
            'La Caixa: el último apunte es del 13/08/2026, hace 66 días.',
        ], $item->details);
        $this->assertSame([], $item->routeParams);
    }

    /** Las facturas de la bandeja, en singular y en plural. */
    public function testLaBandejaDeRecibidas(): void
    {
        $item = $this->only($this->agenda(open: [$this->received()])->items(new \DateTimeImmutable('2026-11-15')));

        $this->assertSame('Revisar 1 factura recibida', $item->title);
        $this->assertSame('accounting_invoices', $item->route);
    }

    /** Una emitida sin cobrar, con su cliente, su número y los días que lleva. */
    public function testEmitidaSinCobrar(): void
    {
        $imidra = $this->issued('1000.00', '2026-10-12');

        $item = $this->only($this->agenda(unpaid: [$imidra])->items(new \DateTimeImmutable('2026-11-15')));

        $this->assertSame('Cobrar 1.210,00 € de IMIDRA (10/2026)', $item->title);
        $this->assertSame(['Emitida el 12/10/2026, hace 34 días. Cuando esté en el banco, se liga al apunte desde la factura.'], $item->details);
        $this->assertSame(['id' => $imidra->getId()], $item->routeParams);
    }

    /** Anulada del todo por su rectificativa y sin cobrar: no queda nada que pedir. */
    public function testEmitidaAnuladaPorSuRectificativaNoCuenta(): void
    {
        $original = $this->issued('100.00', '2026-10-01');
        $rect = $this->rectification($original);

        $this->assertSame([], $this->agenda(unpaid: [$original, $rect])->items(new \DateTimeImmutable('2026-11-15')));
    }

    /** Cobrada y luego rectificada: lo que queda es devolver, a quien se cobró. */
    public function testCobradaYRectificadaEsUnaDevolucion(): void
    {
        $original = $this->issued('100.00', '2026-10-01');
        $original->markPaid((new AccountEntry())->setAmount('121.00'));
        $rect = $this->rectification($original);

        $item = $this->only($this->agenda(unpaid: [$rect])->items(new \DateTimeImmutable('2026-11-15')));

        $this->assertSame('Devolver 121,00 € a IMIDRA (RECT-01/2026)', $item->title);
        $this->assertSame(['id' => $rect->getId()], $item->routeParams);
    }

    /**
     * La remesa del mes: sólo si ya se llevan remesas aquí; sin preparar, preparada
     * sin enviar, o enviada (y entonces nada).
     */
    public function testLaRemesaDelMes(): void
    {
        $today = new \DateTimeImmutable('2026-11-15');
        $september = Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-09-01'), 'Cuotas', 'x.xlsx', [], null);

        $this->assertSame([], $this->agenda()->items($today), 'nunca se ha hecho una remesa aquí');

        $item = $this->only($this->agenda(latestRemittance: $september)->items($today));
        $this->assertSame('Preparar la remesa de cuotas de noviembre', $item->title);

        $draft = new Remittance(Remittance::KIND_BASKET, $today, 'Cuotas', Remittance::SOURCE_APP, null);
        $this->setId($draft);
        $item = $this->only($this->agenda(latestRemittance: $draft, monthRemittance: $draft)->items($today));
        $this->assertSame('Enviar la remesa de cuotas de noviembre', $item->title);
        $this->assertSame(['id' => $draft->getId()], $item->routeParams);

        $sent = Remittance::fromImport(Remittance::KIND_BASKET, $today, 'Cuotas', 'x.xlsx', [], null);
        $this->assertSame([], $this->agenda(latestRemittance: $sent, monthRemittance: $sent)->items($today));
    }

    /** Lo que aprieta va primero; a igual urgencia, en el orden del trabajo. */
    public function testLoQueAprietaVaPrimero(): void
    {
        $fiare = $this->account('Fiare', FinancialAccount::KIND_BANK);
        $items = $this->agenda(
            deliveries: [],
            open: [$this->received()],
            unpaid: [$this->issued('50.00', '2026-10-01')],
            unpaired: [new AccountEntry()],
            accounts: [$fiare],
            lastDates: [$fiare->getId() => new \DateTimeImmutable('2026-08-31')],
        )->items(new \DateTimeImmutable('2026-10-16'));

        // La gestoría aprieta (última semana del plazo); el libro, primero de lo demás.
        $this->assertSame(
            ['Mandar el 3T2026', 'Meter los movimientos', 'Revisar 1 factura', 'Cobrar 60,50 €', 'Revisar 1 traspaso'],
            array_map(static fn (AgendaItem $i): string => implode(' ', \array_slice(explode(' ', $i->title), 0, 3)), $items),
        );
        $this->assertSame('traspasos', $items[4]->fragment);
    }

    /**
     * @param list<ReceivedInvoice>               $received  del trimestre, para la gestoría
     * @param list<ReceivedInvoice>               $open      en la bandeja
     * @param list<IssuedInvoice>                 $unpaid    emitidas sin apunte
     * @param list<FinancialAccount>              $accounts  activas
     * @param array<int, \DateTimeImmutable>      $lastDates último apunte por cuenta
     * @param list<AccountEntry>                  $unpaired  traspasos sin pareja
     * @param list<AccountEntry>                  $withoutInvoice gastos sin factura del trimestre
     * @param list<AccountantDelivery>|null       $deliveries la última entrega de cada trimestre; por omisión,
     *                                                        el 3T2026 entregado, para que la gestoría no salga
     */
    private function agenda(
        array $received = [],
        array $open = [],
        array $unpaid = [],
        array $accounts = [],
        array $lastDates = [],
        array $unpaired = [],
        ?Remittance $latestRemittance = null,
        ?Remittance $monthRemittance = null,
        array $withoutInvoice = [],
        ?array $deliveries = null,
    ): AccountingAgenda {
        $receivedRepo = $this->createStub(ReceivedInvoiceRepository::class);
        $receivedRepo->method('findOpen')->willReturn($open);
        $issuedRepo = $this->createStub(IssuedInvoiceRepository::class);
        $issuedRepo->method('findUnpaid')->willReturn($unpaid);
        $remittances = $this->createStub(RemittanceRepository::class);
        $remittances->method('findLatestOfKind')->willReturn($latestRemittance);
        $remittances->method('findOneBy')->willReturn($monthRemittance);
        $entries = $this->createStub(AccountEntryRepository::class);
        $entries->method('lastDateByAccount')->willReturn($lastDates);
        $entries->method('findUnpairedTransfers')->willReturn($unpaired);
        $entries->method('findExpensesWithoutInvoice')->willReturn($withoutInvoice);
        $latest = [];
        foreach ($deliveries ?? [$this->delivery(2026, 3)] as $delivery) {
            $latest[$delivery->getQuarter()->label()] = $delivery;
        }
        $closed = $this->createStub(ClosedPeriods::class);
        $closed->method('latestOf')->willReturnCallback(static fn (InvoiceQuarter $q): ?AccountantDelivery => $latest[$q->label()] ?? null);
        $closed->method('reopened')->willReturn(array_values(array_filter($latest, static fn (AccountantDelivery $d): bool => !$d->isStanding())));
        $accountRepo = $this->createStub(FinancialAccountRepository::class);
        $accountRepo->method('findActive')->willReturn($accounts);
        $package = $this->createStub(AccountantPackage::class);
        $package->method('invoicesOf')->willReturn($received);
        $book = $this->createStub(IssuedInvoiceBook::class);
        $book->method('invoicesOf')->willReturn([]);

        return new AccountingAgenda(
            $receivedRepo,
            $issuedRepo,
            $remittances,
            $entries,
            $accountRepo,
            $package,
            new InvoiceFileStore(sys_get_temp_dir().'/agenda-test-no-existe', new AsciiSlugger(), new Filesystem()),
            $book,
            $closed,
        );
    }

    /** La entrega de un trimestre; reabierta ese día si se dice. */
    private function delivery(int $year, int $quarter, ?string $reopenedOn = null): AccountantDelivery
    {
        $delivery = new AccountantDelivery(InvoiceQuarter::of($year, $quarter), null, new \DateTimeImmutable('2026-10-01'));
        if ($reopenedOn !== null) {
            $delivery->reopen(null, new \DateTimeImmutable($reopenedOn));
        }

        return $delivery;
    }

    /**
     * @param list<AgendaItem> $items
     */
    private function only(array $items): AgendaItem
    {
        $this->assertCount(1, $items);

        return $items[0];
    }

    private function account(string $name, int $kind): FinancialAccount
    {
        $account = (new FinancialAccount())->setName($name)->setKind($kind);
        (new \ReflectionProperty(FinancialAccount::class, 'id'))->setValue($account, $this->nextId++);

        return $account;
    }

    /** Una factura recibida tal como llega: sin desglose y sin documento guardado. */
    private function received(): ReceivedInvoice
    {
        return new ReceivedInvoice('no-existe-'.$this->nextId++.'.pdf', 'ticket.jpg', 'image/jpeg', ReceivedInvoice::SOURCE_WEB, null);
    }

    /** Emitida a IMIDRA por una base con el 21 % de IVA. */
    private function issued(string $base, string $date): IssuedInvoice
    {
        $invoice = (new IssuedInvoice(IssuedInvoice::SERIES_ORDINARY))
            ->setCustomer((new Customer())->setName('IMIDRA')->setTaxId('S7800035C'))
            ->setIssueDate(new \DateTimeImmutable($date))
            ->addLine(new IssuedInvoiceLine('Formación', $base, '21'));
        $invoice->issue(10, null, new \DateTimeImmutable());
        $this->setId($invoice);

        return $invoice;
    }

    /** Rectificativa que anula la factura entera. */
    private function rectification(IssuedInvoice $original): IssuedInvoice
    {
        $rect = IssuedInvoice::rectificationOf($original);
        $rect->setIssueDate(new \DateTimeImmutable('2026-10-20'));
        $rect->issue(1, null, new \DateTimeImmutable());
        $this->setId($rect);

        return $rect;
    }

    private function setId(object $entity): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $this->nextId++);
    }
}
