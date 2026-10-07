<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Repository\ProviderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El proveedor de una factura que se confirma: el que ya existe con su CIF o, si es
 * la primera vez, uno nuevo con lo que dice la factura.
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
     * Busca o da de alta el proveedor y le completa los datos que falten, sin pisar
     * nada de lo que ya tenga su ficha.
     *
     * @param ReceivedInvoice $invoice Factura con el CIF y la dirección ya revisados.
     * @param string|null     $name    Nombre del proveedor tal como quedó en el apunte.
     */
    public function resolve(ReceivedInvoice $invoice, ?string $name): ?Provider
    {
        $taxId = $invoice->getProviderTaxId();
        if ($taxId === null) {
            return null;
        }

        $provider = $this->providers->findOneByTaxId($taxId);
        if ($provider === null) {
            $provider = (new Provider())
                ->setName(mb_substr(trim((string) $name) !== '' ? trim((string) $name) : $taxId, 0, 255))
                ->setTaxId($taxId);
            $this->em->persist($provider);
        }

        $provider->fillBlanks(
            $invoice->getProviderAddress(),
            $invoice->getProviderPostalCode(),
            $invoice->getProviderTown(),
            $invoice->getProviderProvince(),
        );

        return $provider;
    }
}
