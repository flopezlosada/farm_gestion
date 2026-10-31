<?php

namespace App\Service\Accounting\Agenda;

use App\Entity\AccountantDelivery;
use App\Entity\CashCount;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\OfficialNotice;
use App\Entity\Remittance;
use App\Entity\RemittanceReturn;
use App\Entity\ReturnStatus;
use App\Repository\AccountEntryRepository;
use App\Repository\BankStatementLineRepository;
use App\Repository\CashCountRepository;
use App\Repository\FinancialAccountRepository;
use App\Repository\IssuedInvoiceRepository;
use App\Repository\OfficialNoticeRepository;
use App\Repository\PendingTransferRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Repository\RemittanceRepository;
use App\Repository\RemittanceReturnRepository;
use App\Service\Accounting\CashCount\DayBalances;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\AccountantPackage;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceBook;
use App\Service\Accounting\MonthNames;
use App\Service\Accounting\OfficialNotice\OfficialNoticeAlert;
use App\Service\Accounting\Remittance\BankReturns;
use App\Service\Accounting\Remittance\RemittanceChargeWatch;

/**
 * Lo que queda por hacer en la contabilidad, para la portada: las notificaciones
 * oficiales sin revisar, el libro de los bancos sin meter, los movimientos de los
 * extractos sin anotar, la entrega del trimestre a la gestoría (o volver a mandarlo, si se
 * reabrió), la bandeja de facturas, lo
 * emitido sin cobrar, la remesa del mes, las remesas que no han entrado en el
 * banco, los recibos devueltos, los traspasos a medias y los que se han contado por
 * el bot, y las cajas cuyo último arqueo no cuadra con el libro. Si no devuelve nada,
 * todo está al día.
 *
 * El módulo existe para que el libro esté al día y el paquete de la gestoría llegue
 * completo; esta lista es exactamente eso, ordenado por lo que aprieta. Cada tarea
 * lleva adónde se resuelve: aquí sólo se avisa.
 */
class AccountingAgenda
{
    /** Días antes del plazo de la gestoría en que la entrega pasa a apremiar. */
    public const ACCOUNTANT_SOON_DAYS = 7;

    /** Días sin apuntes a partir de los que el libro de una cuenta de banco se da por atrasado. */
    public const STALE_BOOK_DAYS = 30;

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
        private readonly RemittanceReturnRepository $returns,
        private readonly PendingTransferRepository $pendingTransfers,
        private readonly RemittanceChargeWatch $chargeWatch,
        private readonly CashCountRepository $cashCounts,
        private readonly OfficialNoticeRepository $officialNotices,
        private readonly BankStatementLineRepository $bankLines,
    ) {
    }

    /**
     * Las tareas pendientes a una fecha, las que aprietan primero y, a igual urgencia,
     * en el orden del ritmo de trabajo: notificaciones oficiales, libro, tarjetas,
     * gestoría, facturas, cobros, remesa, traspasos, arqueos.
     *
     * @param \DateTimeImmutable $today Hoy.
     *
     * @return list<AgendaItem>
     */
    public function items(\DateTimeImmutable $today): array
    {
        $items = array_values(array_filter([
            ...$this->unreviewedOfficialNotices(),
            $this->staleBankBooks($today),
            $this->unlinkedBankLines(),
            ...$this->unexplainedCardPayments(),
            ...$this->accountantDeliveries($today),
            $this->openReceivedInvoices(),
            ...$this->uncollectedIssuedInvoices($today),
            $this->monthlyRemittance($today),
            ...$this->uncreditedRemittances(),
            $this->unidentifiedReturns(),
            $this->openReturns(),
            $this->unpairedTransfers(),
            $this->botTransfers(),
            ...$this->cashCountMismatches(),
        ]));

        // usort es estable: a igual urgencia se respeta el orden de arriba.
        usort($items, static fn (AgendaItem $a, AgendaItem $b): int => $a->urgency->value <=> $b->urgency->value);

        return $items;
    }

    /**
     * Las notificaciones oficiales que han avisado por correo y nadie ha mirado en la
     * sede. Una por aviso (llegan pocas) y siempre apremian: a los 10 días cuentan como
     * notificadas, se abran o no.
     *
     * @return list<AgendaItem>
     */
    private function unreviewedOfficialNotices(): array
    {
        return array_map(static fn (OfficialNotice $notice): AgendaItem => new AgendaItem(
            AgendaUrgency::Soon,
            sprintf('Notificación oficial sin revisar (%s)', $notice->getAgency()),
            [
                sprintf('El aviso llegó el %s. %s', $notice->getReceivedAt()->format('d/m/Y'), OfficialNoticeAlert::HOW_TO),
                OfficialNoticeAlert::DEEMED_NOTIFIED,
            ],
            'accounting_official_notices',
        ), $this->officialNotices->findUnreviewed());
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
     * Si el libro de una cuenta de banco, con su último apunte en esa fecha, está
     * atrasado a día de hoy. Lo usa también la portada para pintar en rojo la cuenta.
     * Compara fechas y no `diff()->days`, que es absoluto: un apunte con fecha futura
     * (una errata) no es un libro atrasado.
     *
     * @param \DateTimeImmutable $last  Fecha del último apunte de la cuenta.
     * @param \DateTimeImmutable $today Hoy.
     */
    public static function isStaleBook(\DateTimeImmutable $last, \DateTimeImmutable $today): bool
    {
        return $last < $today->modify(sprintf('-%d days', self::STALE_BOOK_DAYS));
    }

    /**
     * Las cuentas de banco cuyo libro no llega al cierre del trimestre: su libro de
     * caja saldría incompleto.
     *
     * @return list<string>
     */
    private function bankAccountsBehind(\DateTimeImmutable $quarterEnd): array
    {
        return array_values(array_map(
            static fn (array $row): string => sprintf('El libro de %s sólo llega al %s.', $row['account']->getName(), $row['last']->format('d/m/Y')),
            array_filter($this->bankBooks(), static fn (array $row): bool => $row['last'] < $quarterEnd),
        ));
    }

    /**
     * El libro de los bancos sin meter: las cuentas de banco que llevan más de
     * {@see STALE_BOOK_DAYS} días sin apuntes. Todo lo demás (el paquete de la gestoría,
     * los cobros, el cuadre con el banco) sale de ese libro, así que va la primera de las
     * tareas sin plazo. Con una sola cuenta lleva a sus movimientos; con varias, a todos.
     */
    private function staleBankBooks(\DateTimeImmutable $today): ?AgendaItem
    {
        $stale = array_values(array_filter(
            $this->bankBooks(),
            static fn (array $row): bool => self::isStaleBook($row['last'], $today),
        ));
        if ($stale === []) {
            return null;
        }

        if (\count($stale) === 1) {
            ['account' => $account, 'last' => $last] = $stale[0];

            return new AgendaItem(
                AgendaUrgency::Normal,
                sprintf('Meter los movimientos de %s: el libro lleva %d días sin apuntes', $account->getName(), $last->diff($today)->days),
                [sprintf('El último es del %s. Se anotan desde el extracto que se descarga de la banca online.', $last->format('d/m/Y'))],
                'accounting_ledger',
                ['account' => $account->getId()],
            );
        }

        return new AgendaItem(
            AgendaUrgency::Normal,
            sprintf('Meter los movimientos de %d bancos: llevan más de %d días sin apuntes', \count($stale), self::STALE_BOOK_DAYS),
            array_map(
                static fn (array $row): string => sprintf('%s: el último apunte es del %s, hace %d días.', $row['account']->getName(), $row['last']->format('d/m/Y'), $row['last']->diff($today)->days),
                $stale,
            ),
            'accounting_ledger',
        );
    }

    /**
     * Los movimientos de los extractos subidos que el libro aún no tiene: el banco dice
     * que el dinero se movió y no hay apunte que lo diga. Va junto al libro atrasado
     * porque es lo mismo visto desde el banco.
     */
    private function unlinkedBankLines(): ?AgendaItem
    {
        $count = $this->bankLines->countUnlinked();
        if ($count === 0) {
            return null;
        }

        return new AgendaItem(
            AgendaUrgency::Normal,
            $count === 1 ? '1 movimiento del banco sin anotar' : sprintf('%d movimientos del banco sin anotar', $count),
            ['Vienen de los extractos subidos. En la ficha de cada extracto se anotan, o se ligan al apunte que ya los tenía.'],
            'accounting_bank_statements',
        );
    }

    /**
     * Las cuentas de banco activas con la fecha de su último apunte. Las cajas de
     * efectivo no se miran, porque pueden pasar meses sin moverse; tampoco una cuenta
     * sin ningún apunte, que es que no se usa.
     *
     * @return list<array{account: FinancialAccount, last: \DateTimeImmutable}>
     */
    private function bankBooks(): array
    {
        $lastDates = $this->entries->lastDateByAccount();
        $rows = [];
        foreach ($this->accounts->findActive() as $account) {
            /** @var FinancialAccount $account */
            $last = $lastDates[(int) $account->getId()] ?? null;
            if ($account->isBank() && $last !== null) {
                $rows[] = ['account' => $account, 'last' => $last];
            }
        }

        return $rows;
    }

    /**
     * Tarjetas en positivo: la liquidación está anotada y faltan las compras que paga.
     * En negativo es lo normal, lo comprado que el banco aún no ha liquidado. Un saldo
     * por tarjeta: hay una o dos.
     *
     * @return list<AgendaItem>
     */
    private function unexplainedCardPayments(): array
    {
        $items = [];
        foreach ($this->accounts->findActive() as $account) {
            /** @var FinancialAccount $account */
            $balance = $account->isCard() ? (float) $this->entries->balanceFor($account) : 0.0;
            if ($balance > 0) {
                $items[] = new AgendaItem(
                    AgendaUrgency::Normal,
                    sprintf('Anotar las compras de %s: %s € pagados sin explicar', $account->getName(), number_format($balance, 2, ',', '.')),
                    ['La liquidación ya está anotada y faltan las compras que paga. Salen en los movimientos de la tarjeta o en la hoja de gastos.'],
                    'accounting_ledger',
                    ['account' => $account->getId()],
                );
            }
        }

        return $items;
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

    /**
     * Las remesas enviadas que no han entrado en el banco cuando el libro ya llega más
     * allá de su día de cargo ({@see RemittanceChargeWatch}). Aprieta: son las cuotas
     * de un mes sin cobrar. Se quita sola al anotar el abono, o al cambiar en la ficha
     * el día de cargo cuando se vuelve a subir.
     *
     * @return list<AgendaItem>
     */
    private function uncreditedRemittances(): array
    {
        return array_map(static fn (array $row): AgendaItem => new AgendaItem(
            AgendaUrgency::Soon,
            sprintf(
                'La remesa «%s» (%s €, cargo pedido el %s) no ha entrado en el banco y el extracto ya llega al %s',
                $row['remittance']->getLabel(),
                number_format((float) $row['remittance']->getTotal(), 2, ',', '.'),
                $row['remittance']->getChargeDate()?->format('d/m'),
                $row['bookUpTo']->format('d/m'),
            ),
            ['Entra en la banca, mira si está rechazada o pendiente y, si se rechazó entera, vuelve a subirla con fecha nueva y cámbiala en su ficha.'],
            'accounting_remittance_show',
            ['id' => $row['remittance']->getId()],
        ), $this->chargeWatch->uncredited());
    }

    /**
     * Las devoluciones que trae el banco sin saber aún de quién son. Desde la primera
     * remesa que se lleva aquí: las de antes no tienen recibo con el que ligarse.
     */
    private function unidentifiedReturns(): ?AgendaItem
    {
        $from = $this->remittances->firstPeriod();
        $count = $from !== null ? \count($this->entries->findUnlinkedReturnCharges(BankReturns::RETURN_PREFIX, $from)) : 0;
        if ($count === 0) {
            return null;
        }

        return new AgendaItem(
            AgendaUrgency::Normal,
            $count === 1 ? 'Saber de quién es 1 recibo devuelto por el banco' : sprintf('Saber de quién son %d recibos devueltos por el banco', $count),
            ['El nombre sale en el detalle del movimiento en la web de Fiare. Se elige en la ficha de su remesa, en «Devoluciones».'],
            'accounting_remittances',
        );
    }

    /** Los recibos devueltos con algo por hacer: decidir, o meterlos en una remesa. */
    private function openReturns(): ?AgendaItem
    {
        $open = $this->returns->findOpen();
        if ($open === []) {
            return null;
        }

        $byStatus = array_count_values(array_map(static fn (RemittanceReturn $r): string => $r->getStatus()->value, $open));
        $details = array_values(array_filter([
            isset($byStatus[ReturnStatus::Pending->value]) ? sprintf('%d sin decidir qué se hace: es deuda de esa persona.', $byStatus[ReturnStatus::Pending->value]) : null,
            isset($byStatus[ReturnStatus::ToRecharge->value]) ? sprintf('%d por volver a cobrar: se añaden desde el próximo borrador de remesa.', $byStatus[ReturnStatus::ToRecharge->value]) : null,
            isset($byStatus[ReturnStatus::Recharging->value]) ? sprintf('%d en un borrador de remesa que falta enviar.', $byStatus[ReturnStatus::Recharging->value]) : null,
        ]));

        return new AgendaItem(
            AgendaUrgency::Normal,
            \count($open) === 1 ? 'Resolver 1 recibo devuelto' : sprintf('Resolver %d recibos devueltos', \count($open)),
            $details,
            'accounting_returns',
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

    /** Traspasos contados por el bot que esperan a que alguien los revise. */
    private function botTransfers(): ?AgendaItem
    {
        $count = \count($this->pendingTransfers->findUnconfirmed());
        if ($count === 0) {
            return null;
        }

        return new AgendaItem(
            AgendaUrgency::Normal,
            $count === 1 ? 'Confirmar 1 traspaso contado por el bot' : sprintf('Confirmar %d traspasos contados por el bot', $count),
            ['No entran en el libro hasta que alguien los revisa: lo entendido de una nota de voz puede estar mal.'],
            'accounting_transfer_pending',
        );
    }

    /**
     * Las cajas cuyo último arqueo no cuadra con el libro. Se recalcula cada vez y no
     * se usa la diferencia guardada al llegar: si después se anota el ticket olvidado
     * con fecha de ese día o anterior, el aviso se va solo. Lleva a anotar el apunte
     * que falta, con la caja y la fecha puestas; nunca se apunta nada solo.
     *
     * Una consulta de saldo por caja con arqueo: hay dos o tres.
     *
     * @return list<AgendaItem>
     */
    private function cashCountMismatches(): array
    {
        $items = [];
        foreach ($this->cashCounts->findLatestOfActiveCashAccounts() as $count) {
            /** @var CashCount $count */
            $balances = $this->entries->dayBalances($count->getAccount(), $count->getCountDate());
            if ($balances->matches($count->getCountedCents())) {
                continue;
            }
            $difference = $count->getCountedCents() - $balances->closingCents;
            $day = $count->getCountDate()->format('d/m/Y');

            $items[] = new AgendaItem(
                AgendaUrgency::Normal,
                sprintf('El arqueo de %s del %s no cuadra: %s%s', $count->getAccount()->getName(), $day, $difference > 0 ? '+' : '', DayBalances::euros($difference)),
                [
                    sprintf(
                        'Se contaron %s y el libro dice %s al cerrar ese día: %s %s en la caja. Suele ser un ticket sin anotar o un traspaso sin registrar.',
                        DayBalances::euros($count->getCountedCents()),
                        DayBalances::euros($balances->closingCents),
                        $difference < 0 ? 'faltan' : 'sobran',
                        DayBalances::euros(abs($difference)),
                    ),
                    sprintf('Al anotar lo que falta con fecha del %s o anterior, este aviso desaparece solo. No se hace ningún apunte automático.', $day),
                ],
                'accounting_entry_new',
                ['account' => $count->getAccount()->getId(), 'date' => $count->getCountDate()->format('Y-m-d')],
            );
        }

        return $items;
    }
}
