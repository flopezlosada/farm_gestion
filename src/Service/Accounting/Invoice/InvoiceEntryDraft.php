<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Repository\FinancialAccountRepository;
use App\Repository\ReceivedInvoiceRepository;

/**
 * El apunte que propone una factura, para que quien lleva las cuentas sólo tenga que
 * mirarlo y confirmar.
 *
 * Nada de esto se guarda: es el contenido inicial del formulario de apunte de
 * siempre. Lo que se corrija ahí es lo que queda.
 */
class InvoiceEntryDraft
{
    /**
     * @param FinancialAccountRepository $accounts Las cuentas.
     * @param ReceivedInvoiceRepository  $invoices Para el historial del proveedor.
     */
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly ReceivedInvoiceRepository $invoices,
    ) {
    }

    /**
     * Apunte propuesto para una factura.
     *
     * @param ReceivedInvoice $invoice Factura leída (o no: entonces sale casi vacío).
     */
    public function for(ReceivedInvoice $invoice): AccountEntry
    {
        $entry = (new AccountEntry())
            ->setDate($invoice->getInvoiceDate() ?? new \DateTimeImmutable('today'))
            ->setAccount($this->accountFor($invoice))
            ->setCategory($invoice->getSuggestedCategory())
            ->setConcept($this->conceptFor($invoice))
            ->setProviderName($invoice->getProviderName())
            ->setInvoiceNumber($invoice->getInvoiceNumber());

        // Una factura recibida es dinero que sale; un abono (total negativo), que entra.
        if ($invoice->getTotal() !== null && (float) $invoice->getTotal() !== 0.0) {
            $entry->setAmount(number_format(-(float) $invoice->getTotal(), 2, '.', ''));
        }

        return $entry;
    }

    /**
     * La cuenta con que se pagó: la de otras veces a este proveedor y, si es nuevo,
     * la que corresponde a la forma de pago impresa (efectivo a una caja, lo demás
     * al banco). Es una propuesta: se cambia en el formulario.
     */
    private function accountFor(ReceivedInvoice $invoice): ?FinancialAccount
    {
        $active = $this->accounts->findActive();

        $usual = $this->invoices->usualAccountIdFor($invoice->getProviderTaxId());
        foreach ($active as $account) {
            if ($account->getId() === $usual) {
                return $account;
            }
        }

        $wanted = $invoice->getPaymentMethod() === ReceivedInvoice::PAYMENT_CASH
            ? FinancialAccount::KIND_CASH
            : FinancialAccount::KIND_BANK;
        foreach ($active as $account) {
            if ($account->getKind() === $wanted) {
                return $account;
            }
        }

        return null;
    }

    /** «Proveedor · lo comprado», o el nombre del fichero si no se leyó nada. */
    private function conceptFor(ReceivedInvoice $invoice): string
    {
        $parts = array_filter([$invoice->getProviderName(), $invoice->getConcept()]);
        $concept = $parts !== [] ? implode(' · ', $parts) : $invoice->getOriginalName();

        return mb_substr($concept, 0, 255);
    }
}
