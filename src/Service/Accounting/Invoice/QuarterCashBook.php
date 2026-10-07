<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\FinancialAccount;
use App\Repository\AccountEntryRepository;
use App\Repository\FinancialAccountRepository;
use App\Repository\ReceivedInvoiceRepository;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * El libro de caja de un trimestre para la gestoría: todo lo que se movió en cada
 * cuenta, con factura o sin ella, y su saldo. Es lo que la asociación le mandaba a
 * mano en «Bancos <año>.xlsx», con las mismas columnas y una hoja por cuenta.
 *
 * Además de esas columnas lleva «Factura»: el nombre con que va su documento en el
 * mismo ZIP, para cruzar cada movimiento con su papel y ver de un vistazo los que no
 * tienen ninguno.
 */
class QuarterCashBook
{
    private const COLUMNS = ['FECHA', 'PARTIDA', 'CONCEPTO', 'PROVEEDOR', 'Nº FRA', 'INGRESOS', 'GASTOS', 'SALDO', 'FACTURA'];

    /** Una hoja de Excel no admite estos caracteres en el nombre, ni más de 31. */
    private const SHEET_NAME_FORBIDDEN = '/[\\\\\/?*:\[\]]/';

    /**
     * @param AccountEntryRepository    $entries  Los apuntes y los saldos.
     * @param FinancialAccountRepository $accounts Las cuentas.
     * @param ReceivedInvoiceRepository  $invoices Qué apuntes vienen de una factura.
     */
    public function __construct(
        private readonly AccountEntryRepository $entries,
        private readonly FinancialAccountRepository $accounts,
        private readonly ReceivedInvoiceRepository $invoices,
    ) {
    }

    /**
     * El libro del trimestre: una hoja por cuenta que existía en él y estaba activa o
     * se movió. Cada hoja empieza con el saldo al abrir el trimestre y acaba con el
     * saldo al cerrarlo.
     *
     * @param InvoiceQuarter     $quarter    El trimestre.
     * @param array<int, string> $fileByInvoice Nombre en el ZIP de cada factura del trimestre, por id.
     */
    public function build(InvoiceQuarter $quarter, array $fileByInvoice): Spreadsheet
    {
        $entries = $this->entries->listingQueryBuilder(['from' => $quarter->from(), 'to' => $quarter->to()])
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
        $invoiceByEntry = $this->invoices->invoiceIdsByEntry(array_map(static fn (AccountEntry $e) => (int) $e->getId(), $entries));

        $byAccount = [];
        foreach ($entries as $entry) {
            $byAccount[(int) $entry->getAccount()?->getId()][] = $entry;
        }

        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($this->accounts->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']) as $account) {
            $moves = $byAccount[(int) $account->getId()] ?? [];
            if ($account->getOpeningDate() > $quarter->to() || (!$account->isActive() && $moves === [])) {
                continue;
            }
            $this->sheet($book->createSheet(), $account, $quarter, $moves, $invoiceByEntry, $fileByInvoice);
        }
        if ($book->getSheetCount() === 0) {
            $book->createSheet()->setTitle('Sin cuentas');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * La hoja de una cuenta.
     *
     * @param list<AccountEntry> $moves          Sus apuntes del trimestre, por fecha.
     * @param array<int, int>    $invoiceByEntry Factura de cada apunte que la tiene.
     * @param array<int, string> $fileByInvoice  Nombre en el ZIP de cada factura del trimestre.
     */
    private function sheet(Worksheet $sheet, FinancialAccount $account, InvoiceQuarter $quarter, array $moves, array $invoiceByEntry, array $fileByInvoice): void
    {
        $sheet->setTitle(mb_substr((string) preg_replace(self::SHEET_NAME_FORBIDDEN, ' ', $account->getName()), 0, 31));

        $balance = (float) $this->entries->balanceFor($account, $quarter->from()->modify('-1 day'));
        $sheet->fromArray(['Saldo al empezar el '.$quarter->label(), null, null, null, null, null, null, $balance], null, 'A1', true);
        $sheet->fromArray(self::COLUMNS, null, 'A2');
        $sheet->getStyle('A1:I2')->getFont()->setBold(true);
        $sheet->freezePane('A3');

        $row = 3;
        foreach ($moves as $entry) {
            $amount = (float) $entry->getAmount();
            $balance += $amount;
            $invoice = $invoiceByEntry[(int) $entry->getId()] ?? null;
            $sheet->fromArray([
                ExcelDate::PHPToExcel($entry->getDate()),
                $entry->getCategory()?->getName(),
                $entry->getConcept(),
                $entry->getProviderName(),
                $entry->getInvoiceNumber(),
                $amount > 0 ? $amount : null,
                $amount < 0 ? -$amount : null,
                round($balance, 2),
                $invoice === null ? null : ($fileByInvoice[$invoice] ?? 'en el envío de otro trimestre'),
            ], null, 'A'.$row, true);
            ++$row;
        }

        $sheet->fromArray(['Saldo al cerrar el '.$quarter->label(), null, null, null, null, null, null, round($balance, 2)], null, 'A'.$row, true);
        $sheet->getStyle('A'.$row.':I'.$row)->getFont()->setBold(true);
        $sheet->getStyle('A3:A'.$row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('F1:H'.$row)->getNumberFormat()->setFormatCode('#,##0.00 €');
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
