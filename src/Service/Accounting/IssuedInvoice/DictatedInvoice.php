<?php

namespace App\Service\Accounting\IssuedInvoice;

use App\Entity\IssuedInvoice;

/**
 * Lo que sale de un encargo dictado: el borrador y, si el cliente no es ninguno de
 * los dados de alta, el nombre que se oyó, para decírselo a quien lo encargó.
 */
final class DictatedInvoice
{
    /**
     * @param IssuedInvoice $invoice       Borrador, sin guardar.
     * @param string|null   $customerHeard Cliente dicho que no está dado de alta.
     */
    public function __construct(
        public readonly IssuedInvoice $invoice,
        public readonly ?string $customerHeard,
    ) {
    }
}
