<?php

namespace App\Service\Accounting\Agenda;

use App\Entity\AccountantDelivery;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\Remittance;
use App\Repository\AccountEntryRepository;
use App\Repository\FinancialAccountRepository;
use App\Repository\IssuedInvoiceRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Repository\RemittanceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\AccountantPackage;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceBook;
use App\Service\Accounting\MonthNames;

/**
 * Lo que queda por hacer en la contabilidad, para la portada: la entrega del trimestre
 * a la gestoría (o volver a mandarlo, si se reabrió), la bandeja de facturas, lo
 * emitido sin cobrar, la remesa del mes y los traspasos a medias. Si no devuelve
 * nada, todo está al día.
 *
 * El módulo existe para que el libro esté al día y el paquete de la gestoría llegue
 * completo; esta lista es exactamente eso, ordenado por lo que aprieta. Cada tarea
 * lleva adónde se resuelve: aquí sólo se avisa.
 */
class AccountingAgenda
{
    /** Días antes del plazo de la gestoría en que la entrega pasa a apremiar. */
    public const ACCOUNTANT_SOON_DAYS = 7;

    public function __construct(
        private readonly ReceivedInvoiceRepository $receivedInvoices,
        private readonly IssuedInvoiceRepository $issuedInvoices,
        private readonly RemittanceRepository $remittances,
        private readonly AccountEntryRepository $entries,
        private readonly FinancialAccountRepository $accounts,
        private readonly AccountantPackage $package,
        private readonly InvoiceFileStore $files,
        private readonly IssuedInvoiceBook $issuedBook,
        private readonly ClosedPeriods $closed,
    ) {
    }

    /**
     * Las tareas pendientes a una fecha, las que aprietan primero y, a igual urgencia,
     * en el orden del ritmo de trabajo: gestoría, facturas, cobros, remesa, traspasos.
     *
     * @param \DateTimeImmutable $today Hoy.
     *
     * @return list<AgendaItem>
     */
    public function items(\DateTimeImmutable $today): array
    {
        $items = array_values(array_filter([
            ...$this->accountantDeliveries($today),
            $this->openReceivedInvoices(),
            ...$this->uncollectedIssuedInvoices($today),
            $this->monthlyRemittance($today),
            $this->unpairedTransfers(),
        ]));

        // usort es estable: a igual urgencia se respeta el orden de arriba.
        usort($items, static fn (AgendaItem $a, AgendaItem $b): int => $a->urgency->value <=> $b->urgency->value);

        return $items;
    }

    /**
     * Lo que hay que mandar a la gestoría: los trimestres reabiertos para corregirlos,
     * que hay que volver a mandar, y el último trimestre cerrado mientras no conste
     * entregado. Antes del plazo es una tarea más; en su última semana, pasado el plazo
     * o reabierto, aprieta. Entregado, ya no sale: es una tarea hecha.
     *
     * @return list<AgendaItem>
     */
    private function accountantDeliveries(\DateTimeImmutable $today): array
    {
        $items = array_map(fn (AccountantDelivery $d): AgendaItem => $this->deliveryItem(
            $d->getQuarter(),
            AgendaUrgency::Soon,
            sprintf('Volver a mandar el %s a la gestoría: se reabrió el %s', $d->getQuarter()->label(), $d->getReopenedAt()?->format('d/m/Y')),
        ), $this->closed->reopened());

        $quarter = InvoiceQuarter::lastClosed($today);
        if ($this->closed->latestOf($quarter) !== null) {
            return $items;
        }

        $deadline = $quarter->deliveryDeadline();
        $day = $today->setTime(0, 0);
        $deadlineText = sprintf('%d de %s', (int) $deadline->format('j'), mb_strtolower(MonthNames::LONG[(int) $deadline->format('n')]));

        $items[] = $day > $deadline
            ? $this->deliveryItem($quarter, AgendaUrgency::Soon, sprintf('Mandar el %s a la gestoría: el plazo acabó el %s', $quarter->label(), $deadlineText))
            : $this->deliveryItem(
                $quarter,
                (int) $day->diff($deadline)->days <= self::ACCOUNTANT_SOON_DAYS ? AgendaUrgency::Soon : AgendaUrgency::Normal,
                sprintf('Mandar el %s a la gestoría: plazo hasta el %s', $quarter->label(), $deadlineText),
            );

        return $items;
    }

    /**
     * La tarea de mandar un trimestre: cuántas facturas lleva el paquete, cuántas con
     * avisos, qué gastos no tienen factura y qué banco tiene el libro sin llegar al
     * cierre. Es lo que conviene arreglar antes de mandarlo.
     */
    private function deliveryItem(InvoiceQuarter $quarter, AgendaUrgency $urgency, string $title): AgendaItem
    {
        $received = $this->package->invoicesOf($quarter);
        $issued = $this->issuedBook->invoicesOf($quarter);
        $withWarnings = \count(array_filter(
            $received,
            fn ($i): bool => AccountantPackage::warnings($i, $this->files->pathTo($i->getFileName())) !== [],
        )) + \count(array_filter(
            $issued,
            fn ($i): bool => IssuedInvoiceBook::warnings($i, $this->issuedBook->pdfPath($i)) !== [],
        ));
        $withoutInvoice = \count($this->entries->findExpensesWithoutInvoice($quarter->from(), $quarter->to()));

        $details = [sprintf(
            '%d %s y %d %s.',
            \count($received),
            \count($received) === 1 ? 'factura recibida' : 'facturas recibidas',
            \count($issued),
            \count($issued) === 1 ? 'emitida' : 'emitidas',
        )];
        if ($withWarnings > 0) {
            $details[] = sprintf('%d con avisos que mirar antes de mandarlas.', $withWarnings);
        }
        if ($withoutInvoice > 0) {
            $details[] = $withoutInvoice === 1
                ? '1 gasto del trimestre sin factura.'
                : sprintf('%d gastos del trimestre sin factura.', $withoutInvoice);
        }
        foreach ($this->bankAccountsBehind($quarter->to()) as $line) {
            $details[] = $line;
        }

        return new AgendaItem(
            $urgency,
            $title,
            $details,
            'accounting_accountant_export',
            ['year' => $quarter->year, 'quarter' => $quarter->quarter],
        );
    }

    /**
     * Las cuentas de banco cuyo libro no llega al cierre del trimestre: su libro de
     * caja saldría incompleto. Las cajas de efectivo no se miran, porque pueden pasar
     * meses sin moverse; tampoco una cuenta sin ningún apunte, que es que no se usa.
     *
     * @return list<string>
     */
    private function bankAccountsBehind(\DateTimeImmutable $quarterEnd): array
    {
        $lastDates = $this->entries->lastDateByAccount();
        $lines = [];
        foreach ($this->accounts->findActive() as $account) {
            /** @var FinancialAccount $account */
            if (!$account->isBank()) {
                continue;
            }
            $last = $lastDates[(int) $account->getId()] ?? null;
            if ($last !== null && $last < $quarterEnd) {
                $lines[] = sprintf('El libro de %s sólo llega al %s.', $account->getName(), $last->format('d/m/Y'));
            }
        }

        return $lines;
    }

    /** Lo que espera en la bandeja de facturas recibidas. */
    private function openReceivedInvoices(): ?AgendaItem
    {
        $open = \count($this->receivedInvoices->findOpen());
        if ($open === 0) {
            return null;
        }

        return new AgendaItem(
            AgendaUrgency::Normal,
            $open === 1 ? 'Revisar 1 factura recibida' : sprintf('Revisar %d facturas recibidas', $open),
            ['Están en la bandeja: hay que confirmarlas para que entren en el libro y en el paquete de la gestoría.'],
            'accounting_invoices',
        );
    }

    /**
     * Lo emitido que no está ligado a ningún apunte. Una factura y sus rectificativas
     * cuentan juntas, por su neto: anulada del todo no queda nada que cobrar, y si se
     * cobró y luego se rectificó, lo que queda es una devolución.
     *
     * @return list<AgendaItem>
     */
    private function uncollectedIssuedInvoices(\DateTimeImmutable $today): array
    {
        $groups = [];
        foreach ($this->issuedInvoices->findUnpaid() as $invoice) {
            $head = $invoice->getRectifies() ?? $invoice;
            $groups[spl_object_id($head)][] = $invoice;
        }

        $items = [];
        foreach ($groups as $members) {
            $cents = array_sum(array_map(static fn (IssuedInvoice $i): int => (int) round((float) $i->getTotal() * 100), $members));
            if ($cents === 0) {
                continue;
            }
            $first = $members[0];
            $customer = $first->getCustomerName() ?? $first->getCustomer()?->getName() ?? 'sin cliente';
            $amount = number_format(abs($cents) / 100, 2, ',', '.');
            $issuedOn = $first->getIssueDate();

            $items[] = new AgendaItem(
                AgendaUrgency::Normal,
                $cents > 0
                    ? sprintf('Cobrar %s € de %s (%s)', $amount, $customer, $first->getNumberLabel())
                    : sprintf('Devolver %s € a %s (%s)', $amount, $customer, $first->getNumberLabel()),
                $issuedOn !== null
                    ? [sprintf('Emitida el %s, hace %d días. Cuando esté en el banco, se liga al apunte desde la factura.', $issuedOn->format('d/m/Y'), $issuedOn->diff($today)->days)]
                    : [],
                'accounting_issued_invoice_show',
                ['id' => $first->getId()],
            );
        }

        return $items;
    }

    /**
     * La remesa de cuotas del mes, si todavía no se ha enviado. Sólo cuando ya se
     * llevan las remesas aquí (hay alguna de cuotas): si no, avisar cada mes de algo
     * que se hace por otro lado sería ruido.
     */
    private function monthlyRemittance(\DateTimeImmutable $today): ?AgendaItem
    {
        if ($this->remittances->findLatestOfKind(Remittance::KIND_BASKET) === null) {
            return null;
        }

        $period = Remittance::firstOfMonth($today);
        $month = mb_strtolower(MonthNames::LONG[(int) $period->format('n')]);
        $remittance = $this->remittances->findOneBy(['kind' => Remittance::KIND_BASKET, 'period' => $period]);

        if ($remittance === null) {
            return new AgendaItem(
                AgendaUrgency::Normal,
                sprintf('Preparar la remesa de cuotas de %s', $month),
                ['Se prepara a partir de la del mes anterior o del cálculo de cuotas.'],
                'accounting_remittances',
            );
        }
        if ($remittance->getStatus() === Remittance::STATUS_SENT) {
            return null;
        }

        return new AgendaItem(
            AgendaUrgency::Normal,
            sprintf('Enviar la remesa de cuotas de %s', $month),
            ['Está preparada pero no se ha marcado como enviada al banco.'],
            'accounting_remittance_show',
            ['id' => $remittance->getId()],
        );
    }

    /** Traspasos a los que les falta la otra mitad: el saldo de una cuenta no cuadra. */
    private function unpairedTransfers(): ?AgendaItem
    {
        $count = \count($this->entries->findUnpairedTransfers());
        if ($count === 0) {
            return null;
        }

        return new AgendaItem(
            AgendaUrgency::Normal,
            $count === 1 ? 'Revisar 1 traspaso sin la otra mitad' : sprintf('Revisar %d traspasos sin la otra mitad', $count),
            ['O falta anotar el otro lado, o las dos mitades llevan importes distintos.'],
            'accounting_index',
            [],
            'traspasos',
        );
    }
}
