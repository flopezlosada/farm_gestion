<?php

namespace App\Controller;

use App\Entity\Provider;
use App\Form\AccountingProviderType;
use App\Repository\ProviderRepository;
use App\Repository\ReceivedInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los proveedores, vistos desde contabilidad: a quién se le compra, cuánto en el
 * año y con qué facturas.
 *
 * Es la misma tabla que usaba el comercio de la granja: un proveedor es el mismo
 * aunque se le compre pienso para las gallinas o se le pague una factura. Aquí no
 * se borran: tienen facturas y compras colgando.
 */
#[Route('/gestion/accounting/providers')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class AccountingProviderController extends AbstractController
{
    /**
     * Todos los proveedores con lo facturado en el año, de más a menos. Los que
     * pasan del umbral del modelo 347 se marcan.
     */
    #[Route('', name: 'accounting_providers', methods: ['GET'])]
    public function index(Request $request, ProviderRepository $providers): Response
    {
        $year = $request->query->getInt('year', (int) date('Y'));

        return $this->render('accounting/providers.html.twig', [
            'rows' => $providers->findWithYearTotals($year),
            'year' => $year,
            'threshold' => ProviderRepository::MODEL_347_THRESHOLD,
        ]);
    }

    /**
     * La ficha: sus datos y sus facturas, cada una con su apunte.
     */
    #[Route('/{id}', name: 'accounting_provider_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Provider $provider, ReceivedInvoiceRepository $invoices): Response
    {
        return $this->render('accounting/provider_show.html.twig', [
            'provider' => $provider,
            'invoices' => $invoices->findConfirmedByProvider($provider),
        ]);
    }

    /**
     * Cambiar los datos de un proveedor.
     */
    #[Route('/{id}/edit', name: 'accounting_provider_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function edit(Request $request, Provider $provider, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(AccountingProviderType::class, $provider);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Proveedor actualizado.');

            return $this->redirectToRoute('accounting_provider_show', ['id' => $provider->getId()]);
        }

        return $this->render('accounting/provider_form.html.twig', [
            'form' => $form->createView(),
            'provider' => $provider,
        ]);
    }
}
