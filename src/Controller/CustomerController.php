<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Form\CustomerType;
use App\Repository\CustomerRepository;
use App\Repository\IssuedInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los clientes a los que factura la asociación. Se dan de alta una vez y se
 * eligen al hacer cada factura. No se borran: tienen facturas colgando.
 */
#[Route('/gestion/accounting/customers')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class CustomerController extends AbstractController
{
    /** Todos, con lo facturado en el año. */
    #[Route('', name: 'accounting_customers', methods: ['GET'])]
    public function index(Request $request, CustomerRepository $customers): Response
    {
        $year = $request->query->getInt('year', (int) date('Y'));

        return $this->render('accounting/customers.html.twig', [
            'rows' => $customers->findWithYearTotals($year),
            'year' => $year,
        ]);
    }

    #[Route('/new', name: 'accounting_customer_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $customer = new Customer();

        return $this->handleForm($request, $customer, $em, 'Cliente dado de alta.');
    }

    /** La ficha: sus datos y sus facturas. */
    #[Route('/{id}', name: 'accounting_customer_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Customer $customer, IssuedInvoiceRepository $invoices): Response
    {
        return $this->render('accounting/customer_show.html.twig', [
            'customer' => $customer,
            'invoices' => $invoices->findIssuedToCustomer($customer),
        ]);
    }

    /** Cambiar sus datos. Las facturas ya emitidas guardan los que tenían. */
    #[Route('/{id}/edit', name: 'accounting_customer_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function edit(Request $request, Customer $customer, EntityManagerInterface $em): Response
    {
        return $this->handleForm($request, $customer, $em, 'Cliente actualizado.');
    }

    /**
     * Alta y edición son el mismo formulario.
     */
    private function handleForm(Request $request, Customer $customer, EntityManagerInterface $em, string $done): Response
    {
        $form = $this->createForm(CustomerType::class, $customer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($customer);
            $em->flush();
            $this->addFlash('success', $done);

            return $this->redirectToRoute('accounting_customer_show', ['id' => $customer->getId()]);
        }

        return $this->render('accounting/customer_form.html.twig', [
            'form' => $form->createView(),
            'customer' => $customer,
        ]);
    }
}
