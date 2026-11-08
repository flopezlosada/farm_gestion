<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\IssuedInvoice;
use App\Entity\ReceivedInvoice;

/**
 * Las facturas aceptadas que esperan su pago mientras se anota un lote de movimientos
 * del banco. Una factura paga un solo movimiento: al engancharla deja de esperar, y
 * el siguiente movimiento del mismo importe ya no la encuentra.
 */
final class AwaitingInvoices
{
    /** @param list<ReceivedInvoice> $invoices Las que esperan, las más antiguas primero. */
    public function __construct(private array $invoices)
    {
    }

    /**
     * Las que esperan justo ese pago, las más antiguas primero.
     *
     * @param int $cents Importe del cargo, con signo (negativo).
     *
     * @return list<ReceivedInvoice>
     */
    public function paidBy(int $cents): array
    {
        return array_values(array_filter($this->invoices, static fn (ReceivedInvoice $i): bool => self::cents($i) === -$cents));
    }

    /**
     * Las que esperan menos que ese cargo: pueden ser una parte de él.
     *
     * @param int $cents Importe del cargo, con signo (negativo).
     *
     * @return list<ReceivedInvoice>
     */
    public function partOf(int $cents): array
    {
        return array_values(array_filter($this->invoices, static fn (ReceivedInvoice $i): bool => self::cents($i) !== null
            && self::cents($i) > 0 && self::cents($i) < -$cents));
    }

    /** La factura ya está pagada: deja de esperar. */
    public function remove(ReceivedInvoice $invoice): void
    {
        $this->invoices = array_values(array_filter($this->invoices, static fn (ReceivedInvoice $i): bool => $i !== $invoice));
    }

    /** El total en céntimos, o null si la factura no lo trae. */
    private static function cents(ReceivedInvoice $invoice): ?int
    {
        return $invoice->getTotal() !== null ? IssuedInvoice::toCents($invoice->getTotal()) : null;
    }
}
