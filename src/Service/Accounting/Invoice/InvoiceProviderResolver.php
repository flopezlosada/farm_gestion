<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Repository\ProviderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El proveedor de una factura que se confirma: el que se eligió en la revisión o,
 * si es la primera vez, uno nuevo con lo que dice la factura.
 *
 * El CIF es la única señal fiable: el nombre se escribe de mil maneras («LEROY
 * MERLIN ESPAÑA SLU», «Leroy Merlín»). Sin CIF no se reconoce a nadie: es mejor una
 * factura sin proveedor enlazado que enlazarla al que no es.
 */
class InvoiceProviderResolver
{
    /**
     * @param ProviderRepository     $providers Para buscar por CIF.
     * @param EntityManagerInterface $em        Para dar de alta al nuevo.
     */
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * El proveedor con que se anota la factura. Si se eligió uno, ése: el apunte
     * toma su nombre, la factura su CIF (el leído pudo estar mal) y su ficha sólo se
     * completa en lo que tenga vacío, CIF incluido si nadie más lo tiene. Si se dejó
     * en «nuevo», se da de alta con el CIF y la dirección revisados; sin CIF, ninguno.
     *
     * Que el CIF de un «nuevo» no sea ya de otro lo comprueba antes el controller
     * ({@see self::knownFor()}); aquí, si aun así lo es, se enlaza al que existe
     * antes que romper la unicidad.
     *
     * @param ReceivedInvoice $invoice Factura con el CIF y la dirección ya revisados.
     * @param AccountEntry    $entry   Apunte que se va a guardar.
     * @param Provider|null   $chosen  Proveedor elegido en la revisión, o null si es nuevo.
     */
    public function resolve(ReceivedInvoice $invoice, AccountEntry $entry, ?Provider $chosen): ?Provider
    {
        if ($chosen !== null) {
            $taxId = $invoice->getProviderTaxId();
            if ($chosen->getTaxId() === null && $taxId !== null && $this->providers->findOneByTaxId($taxId) === null) {
                $chosen->setTaxId($taxId);
            }
            $this->completeFrom($chosen, $invoice);
            $entry->setProviderName($chosen->getName());
            $invoice->setProviderTaxId($chosen->getTaxId() ?? $taxId);

            return $chosen;
        }

        $taxId = $invoice->getProviderTaxId();
        if ($taxId === null) {
            return null;
        }

        $provider = $this->providers->findOneByTaxId($taxId);
        if ($provider === null) {
            $name = trim((string) $entry->getProviderName());
            $provider = (new Provider())
                ->setName(mb_substr($name !== '' ? $name : $taxId, 0, 255))
                ->setTaxId($taxId);
            $this->em->persist($provider);
        }

        $this->completeFrom($provider, $invoice);

        return $provider;
    }

    /**
     * El proveedor que ya tiene el CIF de la factura, o null si nadie lo tiene. Sirve
     * para dejarlo elegido al abrir la revisión y, al confirmar un «nuevo», para no
     * crear un duplicado ni enlazarlo a escondidas: se pide elegirlo en la lista.
     *
     * @param ReceivedInvoice $invoice Factura con su CIF.
     */
    public function knownFor(ReceivedInvoice $invoice): ?Provider
    {
        return $this->providers->findOneByTaxId($invoice->getProviderTaxId());
    }

    /** La dirección de la factura rellena los huecos de la ficha, sin pisar nada. */
    private function completeFrom(Provider $provider, ReceivedInvoice $invoice): void
    {
        $provider->fillBlanks(
            $invoice->getProviderAddress(),
            $invoice->getProviderPostalCode(),
            $invoice->getProviderTown(),
            $invoice->getProviderProvince(),
        );
    }
}
