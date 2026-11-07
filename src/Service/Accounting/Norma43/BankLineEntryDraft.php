<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\InvoiceEntryDraft;

/**
 * El apunte que anota un movimiento del banco, con lo que se sabe de él: lo que dice
 * el banco (cuenta, fecha, importe y concepto), la partida y el proveedor de las
 * últimas veces y, si una factura aceptada esperaba ese pago, lo de la factura, que
 * manda porque es de este pago. Lo mismo para «Anotar» a mano, que lo enseña
 * prerrellenado, y para lo que la app anota sola ({@see BankLineBooker}).
 */
final class BankLineEntryDraft
{
    /**
     * Pone en el apunte lo que dice el banco del movimiento.
     *
     * @param AccountEntry      $entry El apunte, nuevo.
     * @param BankStatementLine $line  El movimiento.
     */
    public static function fromLine(AccountEntry $entry, BankStatementLine $line): AccountEntry
    {
        return $entry->setAccount($line->getAccount())
            ->setDate($line->getOperationDate())
            ->setAmount($line->getAmount())
            ->setConcept($line->getSuggestedConcept());
    }

    /**
     * Completa el apunte con la partida y el proveedor propuestos y, si la hay, con la
     * factura que espera el pago: su concepto, su número, su proveedor y su partida
     * (o la propuesta, si la factura no trae).
     *
     * @param AccountEntry         $entry    El apunte, ya con lo del banco.
     * @param BudgetCategory|null  $category Partida de las últimas veces.
     * @param Provider|null        $provider Proveedor de las últimas veces.
     * @param ReceivedInvoice|null $awaited  Factura que espera este pago.
     */
    public static function complete(AccountEntry $entry, ?BudgetCategory $category, ?Provider $provider, ?ReceivedInvoice $awaited): AccountEntry
    {
        if ($category !== null) {
            $entry->setCategory($category);
        }
        if ($provider !== null) {
            $entry->setProvider($provider);
        }
        if ($awaited === null) {
            return $entry;
        }
        $entry->setConcept(InvoiceEntryDraft::conceptFor($awaited))
            ->setCategory($awaited->getCategory() ?? $entry->getCategory())
            ->setInvoiceNumber($awaited->getInvoiceNumber());

        return $awaited->getProvider() !== null
            ? $entry->setProvider($awaited->getProvider())
            : $entry->setProvider(null)->setProviderName($awaited->getProviderName());
    }
}
