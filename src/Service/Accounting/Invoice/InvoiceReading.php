<?php

namespace App\Service\Accounting\Invoice;

/**
 * Una lectura que ha salido bien: lo leído y quién lo leyó.
 */
final class InvoiceReading
{
    /**
     * @param ExtractedInvoice $invoice Lo leído.
     * @param string           $model   Modelo que lo leyó.
     */
    public function __construct(
        public readonly ExtractedInvoice $invoice,
        public readonly string $model,
    ) {
    }
}
