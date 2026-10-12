<?php

namespace App\Controller;

use App\Entity\AccountEntry;
use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use App\Entity\User;
use App\Form\IssuedInvoiceType;
use App\Repository\AccountEntryRepository;
use App\Repository\IssuedInvoiceRepository;
use App\Service\Accounting\IssuedInvoice\InvoiceIssuer;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceArchive;
use App\Service\Accounting\IssuedInvoice\IssuedInvoicePdf;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Las facturas que emite la asociación: se hacen en borrador, se emiten (y entonces
 * reciben número y su PDF queda guardado) y, si hay que corregir una emitida, se
 * rectifica.
 *
 * Escribir (hacer, cambiar, emitir, rectificar, tirar un borrador) pide el permiso
 * de edición de contabilidad; ver, el de lectura.
 */
#[Route('/gestion/accounting/issued-invoices')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class IssuedInvoiceController extends AbstractController
{
    /** Los borradores y lo emitido en el año. */
    #[Route('', name: 'accounting_issued_invoices', methods: ['GET'])]
    public function index(Request $request, IssuedInvoiceRepository $invoices, IssuedInvoicePdf $pdf): Response
    {
        $year = $request->query->getInt('year', (int) date('Y'));
        $all = $invoices->findForYear($year);

        return $this->render('accounting/issued_invoices.html.twig', [
            'drafts' => array_values(array_filter($all, static fn (IssuedInvoice $i): bool => $i->isDraft())),
            'issued' => array_values(array_filter($all, static fn (IssuedInvoice $i): bool => $i->isIssued())),
            'year' => $year,
            'hasIssuer' => $pdf->hasIssuer(),
        ]);
    }

    /** Un borrador nuevo, con una línea vacía para empezar. */
    #[Route('/new', name: 'accounting_issued_invoice_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function new(Request $request, EntityManagerInterface $em, AppSettings $settings): Response
    {
        $invoice = new IssuedInvoice(IssuedInvoice::SERIES_ORDINARY, IssuedInvoice::SOURCE_WEB, $this->actor());
        $invoice->setIssueDate(new \DateTimeImmutable('today'));
        // Desde la ficha de un cliente, ya elegido.
        if (($customerId = $request->query->getInt('customer')) > 0) {
            $invoice->setCustomer($em->find(Customer::class, $customerId));
        }
        if (!$request->isMethod('POST')) {
            $invoice->addLine(new IssuedInvoiceLine());
        }

        return $this->handleDraft($request, $invoice, $em, $settings, 'Borrador guardado.');
    }

    /** Ver una factura: el borrador para revisarlo y emitirlo, la emitida tal como salió. */
    #[Route('/{id}', name: 'accounting_issued_invoice_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(IssuedInvoice $invoice, IssuedInvoiceRepository $invoices, IssuedInvoicePdf $pdf, AccountEntryRepository $entries): Response
    {
        return $this->render('accounting/issued_invoice_show.html.twig', [
            'invoice' => $invoice,
            'problems' => $invoice->isDraft() ? $invoice->issueProblems() : [],
            'hasIssuer' => $pdf->hasIssuer(),
            'rectifications' => $invoice->isIssued() ? $invoices->findRectificationsOf($invoice) : [],
            'paymentCandidates' => $this->paymentCandidates($invoice, $entries),
        ]);
    }

    /**
     * Ligar la factura al apunte en que se cobró. Sólo se acepta uno de los que
     * propone la ficha: así no se liga un apunte de otro importe ni uno que ya
     * explica otra factura.
     */
    #[Route('/{id}/payment', name: 'accounting_issued_invoice_payment', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function payment(Request $request, IssuedInvoice $invoice, AccountEntryRepository $entries, EntityManagerInterface $em): Response
    {
        $back = $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        if (!$this->isCsrfTokenValid('payment-invoice-' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }

        $entryId = $request->request->getInt('entry');
        $entry = current(array_filter(
            $this->paymentCandidates($invoice, $entries),
            static fn (AccountEntry $e): bool => $e->getId() === $entryId,
        ));
        if ($entry === false) {
            $this->addFlash('warning', 'Ese apunte ya no casa con la factura: puede que se haya ligado a otra o que ya esté cobrada.');

            return $back;
        }

        $invoice->markPaid($entry);
        $em->flush();
        $this->addFlash('success', sprintf('Cobrada el %s en %s.', $entry->getDate()->format('d/m/Y'), $entry->getAccount()->getName()));

        return $back;
    }

    /** Deshacer el cobro: el apunte ligado no era el suyo. El apunte sigue en el libro. */
    #[Route('/{id}/payment/remove', name: 'accounting_issued_invoice_payment_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function paymentRemove(Request $request, IssuedInvoice $invoice, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('payment-remove-invoice-' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $invoice->unmarkPaid();
            $em->flush();
            $this->addFlash('success', 'La factura vuelve a estar sin cobrar. El apunte sigue en el libro.');
        } else {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');
        }

        return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
    }

    /** Cambiar un borrador. Una emitida no se cambia: se rectifica. */
    #[Route('/{id}/edit', name: 'accounting_issued_invoice_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function edit(Request $request, IssuedInvoice $invoice, EntityManagerInterface $em, AppSettings $settings): Response
    {
        if ($invoice->isIssued()) {
            $this->addFlash('warning', 'Una factura emitida no se cambia: si hay que corregirla, se rectifica.');

            return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        }

        return $this->handleDraft($request, $invoice, $em, $settings, 'Borrador guardado.');
    }

    /** Darle número y cerrarla. */
    #[Route('/{id}/issue', name: 'accounting_issued_invoice_issue', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function issue(Request $request, IssuedInvoice $invoice, InvoiceIssuer $issuer): Response
    {
        if (!$this->isCsrfTokenValid('issue-invoice-' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        }

        $problem = $issuer->issue($invoice, $this->actor());
        $problem === null
            ? $this->addFlash('success', sprintf('Emitida con el número %s. El PDF ya está guardado.', $invoice->getNumberLabel()))
            : $this->addFlash('danger', $problem);

        return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
    }

    /** Hacer una rectificativa de una emitida: nace en borrador, anulándola entera. */
    #[Route('/{id}/rectify', name: 'accounting_issued_invoice_rectify', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function rectify(Request $request, IssuedInvoice $invoice, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('rectify-invoice-' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        }
        if (!$invoice->isIssued() || $invoice->isRectifying()) {
            $this->addFlash('warning', 'Sólo se rectifica una factura emitida, y no otra rectificativa.');

            return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        }

        $rectification = IssuedInvoice::rectificationOf($invoice, $this->actor());
        $rectification->setIssueDate(new \DateTimeImmutable('today'));
        $em->persist($rectification);
        $em->flush();
        $this->addFlash('success', sprintf('Borrador de rectificativa de la %s. Tal cual la anula entera; deja sólo lo que cambia si es una corrección parcial.', $invoice->getNumberLabel()));

        return $this->redirectToRoute('accounting_issued_invoice_edit', ['id' => $rectification->getId()]);
    }

    /** Tirar un borrador. Una emitida no se borra nunca. */
    #[Route('/{id}/delete', name: 'accounting_issued_invoice_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function delete(Request $request, IssuedInvoice $invoice, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-invoice-' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        }
        if ($invoice->isIssued()) {
            $this->addFlash('warning', 'Una factura emitida no se borra: si no vale, se rectifica.');

            return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        }

        $em->remove($invoice);
        $em->flush();
        $this->addFlash('success', 'Borrador tirado.');

        return $this->redirectToRoute('accounting_issued_invoices');
    }

    /**
     * El PDF. El de una emitida es el que se guardó al emitirla; el de un borrador se
     * hace al momento, con «BORRADOR» cruzado.
     */
    #[Route('/{id}/pdf', name: 'accounting_issued_invoice_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(IssuedInvoice $invoice, IssuedInvoicePdf $pdf, IssuedInvoiceArchive $archive): Response
    {
        $name = IssuedInvoicePdf::downloadName($invoice);
        if ($invoice->isDraft()) {
            return new Response($pdf->render($invoice), Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name),
            ]);
        }

        $path = $archive->pathTo($invoice);
        if ($path === null) {
            throw $this->createNotFoundException('El PDF de esta factura no está en el servidor.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name);

        return $response;
    }

    /**
     * Alta y edición de un borrador son el mismo formulario. Si hay líneas exentas
     * y no se ha escrito el motivo, se propone el texto de la configuración.
     */
    private function handleDraft(Request $request, IssuedInvoice $invoice, EntityManagerInterface $em, AppSettings $settings, string $done): Response
    {
        $form = $this->createForm(IssuedInvoiceType::class, $invoice);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($invoice->hasExemptLines() && $invoice->getExemptionNote() === null) {
                $invoice->setExemptionNote($settings->getString(AppSettings::ISSUED_INVOICE_EXEMPTION));
            }
            $em->persist($invoice);
            $em->flush();
            $this->addFlash('success', $done);

            return $this->redirectToRoute('accounting_issued_invoice_show', ['id' => $invoice->getId()]);
        }

        return $this->render('accounting/issued_invoice_form.html.twig', [
            'form' => $form->createView(),
            'invoice' => $invoice,
            'exemptionDefault' => $settings->getString(AppSettings::ISSUED_INVOICE_EXEMPTION),
        ]);
    }

    /**
     * Apuntes que pueden ser el cobro de una emitida todavía sin cobrar: su total
     * exacto (negativo en una rectificativa, que se devuelve) desde poco antes de su
     * fecha hasta {@see AccountEntryRepository::COLLECTION_DAYS_AFTER} días después.
     *
     * @return list<AccountEntry>
     */
    private function paymentCandidates(IssuedInvoice $invoice, AccountEntryRepository $entries): array
    {
        if (!$invoice->isIssued() || $invoice->isPaid() || IssuedInvoice::toCents($invoice->getTotal()) === 0) {
            return [];
        }

        return $entries->findPaymentCandidates($invoice->getTotal(), $invoice->getIssueDate(), 5, AccountEntryRepository::COLLECTION_DAYS_AFTER);
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
