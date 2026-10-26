<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\ReceivedInvoice;

/**
 * Un pago del libro que explica una factura junto con otras del mismo proveedor: su
 * importe es la suma de todas.
 */
final class GroupedPayment
{
    /**
     * @param AccountEntry          $payment El pago, sin ninguna factura todavía.
     * @param list<ReceivedInvoice> $others  Las otras facturas que paga, aún en la bandeja.
     */
    public function __construct(
        public readonly AccountEntry $payment,
        public readonly array $others,
    ) {
    }
}
