<?php

namespace App\Controller;

use App\Entity\ReceivedInvoice;
use App\Entity\User;
use App\EventListener\InvoiceReadListener;
use App\Form\AccountEntryType;
use App\Form\ReceivedInvoiceConfirmType;
use App\Form\ReceivedInvoiceUploadType;
use App\Repository\BudgetCategoryRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Invoice\InvoiceEntryDraft;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceProviderResolver;
use App\Service\Accounting\Invoice\InvoiceReadQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La bandeja de facturas recibidas: se suben, una máquina las lee y quien lleva las
 * cuentas las convierte en apuntes con un clic.
 *
 * La meta es que nadie teclee una factura. Lo que llega leído se confirma tal cual o
 * se corrige en el mismo formulario de apunte de siempre; lo que no se pudo leer se
 * completa ahí a mano, con el documento al lado.
 */
#[Route('/gestion/accounting/invoices')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class ReceivedInvoiceController extends AbstractController
{
    /** Cuántas anotadas se enseñan debajo, para comprobar lo último que se hizo. */
    private const RECENT = 10;

    /**
     * La bandeja: subir, lo que espera lectura, lo que espera confirmación.
     */
    #[Route('', name: 'accounting_invoices', methods: ['GET'])]
    public function index(ReceivedInvoiceRepository $invoices, InvoiceReadQueue $queue): Response
    {
        $open = $invoices->findOpen();
        $pending = array_values(array_filter($open, static fn (ReceivedInvoice $i): bool => $i->getStatus() === ReceivedInvoice::STATUS_PENDING));

        return $this->render('accounting/invoices.html.twig', [
            'upload' => $this->createForm(ReceivedInvoiceUploadType::class, null, [
                'action' => $this->generateUrl('accounting_invoices_upload'),
            ])->createView(),
            'open' => $open,
            'queue' => $this->queueStatus($pending, $queue->isEnabled()),
            'recent' => $invoices->findRecentlyConfirmed(self::RECENT),
        ]);
    }

    /**
     * Guarda las facturas subidas y las deja en cola. La lectura arranca en cuanto
     * la respuesta ha salido ({@see InvoiceReadListener}), así que quien sube no
     * espera a que termine.
     */
    #[Route('/upload', name: 'accounting_invoices_upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $em, InvoiceFileStore $files): Response
    {
        $form = $this->createForm(ReceivedInvoiceUploadType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
            if (!$form->isSubmitted()) {
                // Lo que pasa cuando el fichero supera lo que admite el servidor:
                // PHP lo descarta entero y el formulario llega vacío.
                $this->addFlash('danger', 'No ha llegado ninguna factura. Si eran muy pesadas, súbelas de una en una.');
            }

            return $this->redirectToRoute('accounting_invoices');
        }

        $saved = [];
        /** @var UploadedFile $upload */
        foreach ($form->get('files')->getData() as $upload) {
            $mimeType = (string) $upload->getMimeType();
            $invoice = new ReceivedInvoice(
                $files->store($upload),
                mb_substr($upload->getClientOriginalName(), 0, 255),
                $mimeType,
                ReceivedInvoice::SOURCE_WEB,
                $this->currentUser(),
            );
            $em->persist($invoice);
            $saved[] = $invoice;
        }
        $em->flush();

        $request->attributes->set(InvoiceReadListener::ATTRIBUTE, array_map(
            static fn (ReceivedInvoice $i): int => (int) $i->getId(),
            $saved,
        ));

        $this->addFlash('success', \count($saved) === 1
            ? 'Factura recibida. Se está leyendo: en unos segundos aparece con sus datos.'
            : sprintf('%d facturas recibidas. Se están leyendo: en unos segundos aparecen con sus datos.', \count($saved)));

        return $this->redirectToRoute('accounting_invoices');
    }

    /**
     * Revisar una factura y convertirla en apunte. El formulario llega relleno con lo
     * leído: si está bien, basta con guardar.
     */
    #[Route('/{id}', name: 'accounting_invoice_review', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function review(
        Request $request,
        ReceivedInvoice $invoice,
        EntityManagerInterface $em,
        InvoiceEntryDraft $drafts,
        BudgetCategoryRepository $categories,
        ReceivedInvoiceRepository $invoices,
        InvoiceProviderResolver $providers,
    ): Response {
        if (!$invoice->isOpen()) {
            $this->addFlash('warning', 'Esta factura ya no está en la bandeja.');

            return $this->redirectToRoute('accounting_invoices');
        }

        $entry = $drafts->for($invoice);
        $form = $this->createForm(ReceivedInvoiceConfirmType::class, $invoice, [
            'entry' => $entry,
            'provider' => $providers->knownFor($invoice),
        ]);
        $form->handleRequest($request);

        $chosen = $form->isSubmitted() ? $form->get('provider')->getData() : null;
        $clash = $form->isSubmitted() && $chosen === null ? $providers->knownFor($invoice) : null;
        if ($clash !== null) {
            $form->get('providerTaxId')->addError(new FormError(sprintf(
                'Ese CIF ya es de «%s»: pulsa «¿No es correcto?» y elígelo entre los que ya tenemos.',
                $clash->getName(),
            )));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $entry->setCreatedBy($this->currentUser());
            $invoice->confirm($entry, $providers->resolve($invoice, $entry, $chosen));
            $em->persist($entry);
            $em->flush();
            $this->addFlash('success', sprintf('Anotado: %s.', $entry->getConcept()));

            // Quien confirma suele ir una tras otra: la siguiente lista para revisar.
            foreach ($invoices->findOpen() as $next) {
                if ($next->getStatus() !== ReceivedInvoice::STATUS_PENDING) {
                    return $this->redirectToRoute('accounting_invoice_review', ['id' => $next->getId()]);
                }
            }

            return $this->redirectToRoute('accounting_invoices');
        }

        return $this->render('accounting/invoice_review.html.twig', [
            'invoice' => $invoice,
            'twin' => $invoices->findAlreadyConfirmedTwin($invoice),
            'form' => $form->createView(),
            'suggestions' => AccountEntryType::suggestedDirections($categories->findActive()),
        ]);
    }

    /**
     * El documento, para verlo al lado del formulario. Sale por aquí y no por una
     * URL pública: exige el permiso de contabilidad.
     */
    #[Route('/{id}/file', name: 'accounting_invoice_file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function document(ReceivedInvoice $invoice, InvoiceFileStore $files): Response
    {
        $path = $files->pathTo($invoice->getFileName());
        if ($path === null) {
            throw $this->createNotFoundException('El fichero de esta factura no está en el servidor.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $invoice->getMimeType());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $invoice->getOriginalName(), 'factura');
        // Se enseña dentro de la página de revisión, que es de este mismo sitio. Sin
        // esto la política general (DENY) dejaría el marco en blanco.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }

    /**
     * Apartar una factura sin anotarla: duplicada, equivocada o no era una factura.
     * El fichero se conserva: descartar no es borrar.
     */
    #[Route('/{id}/discard', name: 'accounting_invoice_discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(Request $request, ReceivedInvoice $invoice, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('discard-invoice-' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición ha caducado; vuelve a intentarlo.');

            return $this->redirectToRoute('accounting_invoice_review', ['id' => $invoice->getId()]);
        }

        if ($invoice->isOpen()) {
            $invoice->discard();
            $em->flush();
            $this->addFlash('success', sprintf('Descartada: %s.', $invoice->getOriginalName()));
        }

        return $this->redirectToRoute('accounting_invoices');
    }

    /**
     * Cómo va la cola, para el aviso de la bandeja: cuántas esperan, por qué y
     * cuándo es el próximo intento.
     *
     * @param list<ReceivedInvoice> $pending Las que esperan lectura.
     * @param bool                  $enabled Si hay con qué leer.
     *
     * @return array{count: int, enabled: bool, waiting: int, reading: int, lastError: ?string, nextAttempt: ?\DateTimeImmutable}
     */
    private function queueStatus(array $pending, bool $enabled): array
    {
        $now = new \DateTimeImmutable();
        $waiting = 0;
        $lastError = null;
        $nextAttempt = null;

        foreach ($pending as $invoice) {
            // «Esperando» es lo que ya falló al menos una vez y aguarda reintento;
            // lo recién subido está leyéndose y no merece un aviso todavía.
            if ($invoice->getLastError() === null) {
                continue;
            }
            ++$waiting;
            $lastError = $invoice->getLastError();
            $at = $invoice->getNextAttemptAt();
            if ($at !== null && $at > $now && ($nextAttempt === null || $at < $nextAttempt)) {
                $nextAttempt = $at;
            }
        }

        return [
            'count' => \count($pending),
            'enabled' => $enabled,
            'waiting' => $waiting,
            'reading' => \count($pending) - $waiting,
            'lastError' => $lastError,
            'nextAttempt' => $nextAttempt,
        ];
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
