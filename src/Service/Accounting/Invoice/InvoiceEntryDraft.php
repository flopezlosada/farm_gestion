<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Repository\FinancialAccountRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use Symfony\Component\Clock\ClockInterface;

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
     * @param ClosedPeriods              $closed   Qué trimestres están entregados.
     * @param ClockInterface             $clock    Hoy.
     */
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly ReceivedInvoiceRepository $invoices,
        private readonly ClosedPeriods $closed,
        private readonly ClockInterface $clock,
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
            ->setDate($this->dateFor($invoice))
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
     * El día de la factura, o hoy si no se leyó o si su trimestre ya está entregado a
     * la gestoría: ahí no se puede anotar nada, y el pago que no estaba en el libro se
     * anota cuando se conoce, igual que la factura va en el paquete del trimestre de hoy.
     */
    private function dateFor(ReceivedInvoice $invoice): \DateTimeImmutable
    {
        $date = $invoice->getInvoiceDate();
        if ($date !== null && $this->closed->closingDeliveryFor($date) === null) {
            return $date;
        }

        return $this->clock->now()->setTime(0, 0);
    }

    /**
     * La cuenta con que se pagó. Pagada con tarjeta, la tarjeta si hay una: las
     * compras con tarjeta anteriores a tener la cuenta están en el banco, y la de otras
     * veces la llevaría allí. Si no, la de otras veces a este proveedor y, si es nuevo,
     * la que corresponde a la forma de pago impresa (efectivo a una caja, lo demás al
     * banco). Es una propuesta: se cambia en el formulario.
     */
    private function accountFor(ReceivedInvoice $invoice): ?FinancialAccount
    {
        $active = $this->accounts->findActive();
        $ofKind = static fn (int $kind): ?FinancialAccount => array_values(array_filter(
            $active,
            static fn (FinancialAccount $account): bool => $account->getKind() === $kind,
        ))[0] ?? null;

        $method = $invoice->getPaymentMethod();
        $card = $method === ReceivedInvoice::PAYMENT_CARD ? $ofKind(FinancialAccount::KIND_CARD) : null;
        if ($card !== null) {
            return $card;
        }

        $usual = $this->invoices->usualAccountIdFor($invoice->getProviderTaxId());
        foreach ($active as $account) {
            if ($account->getId() === $usual) {
                return $account;
            }
        }

        return $ofKind($method === ReceivedInvoice::PAYMENT_CASH ? FinancialAccount::KIND_CASH : FinancialAccount::KIND_BANK);
    }

    /** «Proveedor · lo comprado», o el nombre del fichero si no se leyó nada. */
    private function conceptFor(ReceivedInvoice $invoice): string
    {
        $parts = array_filter([$invoice->getProviderName(), $invoice->getConcept()]);
        $concept = $parts !== [] ? implode(' · ', $parts) : $invoice->getOriginalName();

        return mb_substr($concept, 0, 255);
    }
}
