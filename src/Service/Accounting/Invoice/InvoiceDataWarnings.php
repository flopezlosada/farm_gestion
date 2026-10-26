<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Service\AppSettings;

/**
 * Lo que conviene mirar en los datos de una factura recibida antes de anotarla.
 *
 * Sólo avisa: quien confirma decide. Dos cosas, las que la gestoría rechazaría:
 * que la factura no esté a nombre de la asociación y que el CIF del proveedor no
 * cuadre con su control. El nombre o la dirección de la asociación mal escritos
 * no avisan: lo que vale fiscalmente es el CIF.
 */
class InvoiceDataWarnings
{
    /** Los documentos que tienen que llevar los datos del cliente. Un ticket o una factura simplificada no los llevan. */
    private const WITH_CUSTOMER = ['factura'];

    /**
     * @param AppSettings $settings De donde sale el CIF de la asociación (quién emite las facturas).
     */
    public function __construct(private readonly AppSettings $settings)
    {
    }

    /**
     * Los avisos de una factura, en palabras de quien la revisa.
     *
     * @param ReceivedInvoice $invoice Factura a revisar.
     *
     * @return list<string>
     */
    public function for(ReceivedInvoice $invoice): array
    {
        return array_values(array_filter([
            $this->customerWarning($invoice),
            $this->providerTaxIdWarning($invoice),
        ]));
    }

    /**
     * Si la factura no está a nombre de la asociación. Sólo con lo que leyó la
     * máquina: una factura completada a mano no tiene cliente leído, y sin el CIF
     * de la asociación en los ajustes no hay con qué comparar.
     */
    private function customerWarning(ReceivedInvoice $invoice): ?string
    {
        $own = $this->associationTaxId();
        if ($own === null || $invoice->getReadAt() === null || !\in_array($invoice->getDocumentType(), self::WITH_CUSTOMER, true)) {
            return null;
        }

        $read = $invoice->getCustomerTaxId();

        return match (true) {
            $read === null => 'La factura no lleva el CIF de la asociación. Para la gestoría tiene que ir a su nombre: si de verdad no lo lleva, pide al proveedor que la rehaga.',
            $read !== $own => sprintf(
                'La factura está a nombre de %s (%s), no de la asociación (%s). Si es una errata del proveedor, pídele que la rehaga; si no es nuestra, descártala.',
                $invoice->getCustomerName() ?? 'otro cliente',
                $read,
                $own,
            ),
            default => null,
        };
    }

    /** Si el CIF del proveedor no cuadra con su control. */
    private function providerTaxIdWarning(ReceivedInvoice $invoice): ?string
    {
        $taxId = $invoice->getProviderTaxId();

        return $taxId !== null && !SpanishTaxId::isValid($taxId)
            ? sprintf('El CIF del proveedor (%s) no es válido: o el proveedor lo tiene mal escrito o se ha leído mal. Compáralo con el documento.', $taxId)
            : null;
    }

    /**
     * El CIF de la asociación: la primera palabra válida del ajuste «quién emite
     * las facturas», que lo lleva en una de sus líneas («CIF: G-…»).
     */
    private function associationTaxId(): ?string
    {
        $words = preg_split('/[\s:,;\/]+/', str_replace(['-', '.'], '', $this->settings->getString(AppSettings::ISSUED_INVOICE_ISSUER)));
        foreach ($words === false ? [] : $words as $word) {
            $taxId = Provider::normalizeTaxId($word);
            if ($taxId !== null && SpanishTaxId::isValid($taxId)) {
                return $taxId;
            }
        }

        return null;
    }
}
