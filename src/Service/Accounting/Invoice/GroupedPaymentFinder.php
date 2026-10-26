<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\IssuedInvoice;
use App\Entity\ReceivedInvoice;
use App\Repository\AccountEntryRepository;
use App\Repository\ReceivedInvoiceRepository;

/**
 * Los pagos del libro que explican una factura junto con otras del mismo proveedor:
 * un proveedor que cobra de una vez lo de varias facturas deja en el banco un solo
 * movimiento, que no coincide con ninguna de ellas por separado.
 *
 * Se prueban las combinaciones de las otras facturas de ese CIF que siguen en la
 * bandeja, y se busca en el libro un pago sin factura por alguna de esas sumas.
 */
class GroupedPaymentFinder
{
    /** Cuántas otras facturas se combinan como mucho: 8 son 255 sumas. */
    private const MAX_OTHERS = 8;

    public function __construct(
        private readonly ReceivedInvoiceRepository $invoices,
        private readonly AccountEntryRepository $entries,
    ) {
    }

    /**
     * Los pagos que encajan, cada uno con las otras facturas que paga. Si una misma
     * suma sale con combinaciones distintas, la de menos facturas.
     *
     * @return list<GroupedPayment>
     */
    public function for(ReceivedInvoice $invoice, int $limit = 3): array
    {
        if ($invoice->getTotal() === null || $invoice->getInvoiceDate() === null) {
            return [];
        }
        $others = $this->invoices->findOpenFromProvider($invoice, self::MAX_OTHERS);
        if ($others === []) {
            return [];
        }

        $bySum = $this->combinationsBySum(IssuedInvoice::toCents($invoice->getTotal()), $others);
        $dates = array_map(static fn (ReceivedInvoice $i): string => $i->getInvoiceDate()->format('Y-m-d'), [$invoice, ...$others]);
        $payments = $this->entries->findPaymentsAmong(
            array_map(static fn (int $cents): string => IssuedInvoice::fromCents(-$cents), array_keys($bySum)),
            (new \DateTimeImmutable(min($dates)))->modify(sprintf('-%d days', AccountEntryRepository::PAYMENT_DAYS_BEFORE)),
            (new \DateTimeImmutable(max($dates)))->modify(sprintf('+%d days', AccountEntryRepository::PAYMENT_DAYS_AFTER)),
            $limit,
        );

        return array_map(
            static fn (AccountEntry $payment): GroupedPayment => new GroupedPayment($payment, $bySum[-IssuedInvoice::toCents($payment->getAmount())]),
            $payments,
        );
    }

    /**
     * Cada suma posible de esta factura con alguna de las otras, en céntimos, con las
     * otras que la forman: `[céntimos => facturas]`. Sólo sumas que son un gasto, y
     * ninguna igual a la factura sola (un abono entre las otras puede darla): ese pago
     * ya sale entre los de importe exacto.
     *
     * @param list<ReceivedInvoice> $others
     *
     * @return array<int, list<ReceivedInvoice>>
     */
    private function combinationsBySum(int $base, array $others): array
    {
        $subsets = [];
        for ($mask = 1; $mask < 1 << \count($others); ++$mask) {
            $subsets[] = array_values(array_filter($others, static fn (int $i): bool => ($mask & (1 << $i)) !== 0, \ARRAY_FILTER_USE_KEY));
        }
        // Menos facturas primero: a igual suma, la explicación más sencilla.
        usort($subsets, static fn (array $a, array $b): int => \count($a) <=> \count($b));

        $bySum = [];
        foreach ($subsets as $subset) {
            $sum = $base + array_sum(array_map(static fn (ReceivedInvoice $i): int => IssuedInvoice::toCents((string) $i->getTotal()), $subset));
            if ($sum > 0 && $sum !== $base) {
                $bySum[$sum] ??= $subset;
            }
        }

        return $bySum;
    }
}
