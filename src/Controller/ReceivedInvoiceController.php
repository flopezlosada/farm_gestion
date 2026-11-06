<?php

namespace App\Controller;

use App\Entity\AccountEntry;
use App\Entity\IssuedInvoice;
use App\Entity\ReceivedInvoice;
use App\Entity\User;
use App\EventListener\InvoiceReadListener;
use App\Form\AccountEntryType;
use App\Form\ReceivedInvoiceConfirmType;
use App\Form\ReceivedInvoiceUploadType;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\GroupedPaymentFinder;
use App\Service\Accounting\Invoice\InvoiceDataWarnings;
use App\Service\Accounting\Invoice\InvoiceEntryDraft;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceProviderResolver;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\Invoice\InvoiceReadQueue;
use App\Service\Accounting\Invoice\ReviewQueuePosition;
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
 * cuentas las acepta con un clic. Pagadas en caja o con tarjeta, con su apunte;
 * pagadas por banco, esperando a que el pago llegue con el extracto.
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
            'awaiting' => $invoices->findAwaitingPayment(),
            'recent' => $invoices->findRecentlyResolved(self::RECENT),
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
     * Revisar una factura y aceptarla. El formulario llega relleno con lo leído: si
     * está bien, basta con guardar. Una ya aceptada o descartada se enseña tal como
     * quedó, sin formulario.
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
        InvoiceFileStore $files,
        AccountEntryRepository $entries,
        GroupedPaymentFinder $groupedPayments,
        InvoiceDataWarnings $dataWarnings,
        ClosedPeriods $closed,
    ): Response {
        if (!$invoice->isOpen()) {
            // Anotada o descartada ya no se revisa: se enseña tal como quedó. Un envío
            // del formulario a destiempo no crea un segundo apunte.
            if ($request->isMethod('POST')) {
                $this->addFlash('warning', 'Esta factura ya no está en la bandeja.');

                return $this->redirectToRoute('accounting_invoices');
            }

            return $this->render('accounting/invoice_show.html.twig', [
                'invoice' => $invoice,
                'hasDocument' => $files->pathTo($invoice->getFileName()) !== null,
            ]);
        }

        $entry = $drafts->for($invoice);
        // El pago que ya trajo el banco, si está: se calcula con lo leído, no con lo
        // que se teclee, para que las opciones sean las mismas al abrir y al guardar.
        // Alrededor del día de la factura, no del que propone el apunte: el de una que
        // llega tarde a un trimestre entregado es hoy, y su pago estará en aquel.
        $paidAround = $invoice->getInvoiceDate() ?? $entry->getDate();
        $candidates = (float) $entry->getAmount() !== 0.0 && $paidAround !== null
            ? $entries->findPaymentCandidates($entry->getAmount(), $paidAround)
            : [];
        // El pago de varias facturas del mismo proveedor a la vez, si lo hay.
        $grouped = $groupedPayments->for($invoice);
        $othersByPayment = [];
        foreach ($grouped as $group) {
            $othersByPayment[$group->payment->getId()] = $group->others;
        }
        $form = $this->createForm(ReceivedInvoiceConfirmType::class, $invoice, [
            'entry' => $entry,
            'provider' => $providers->knownFor($invoice),
            'candidates' => $candidates,
            'grouped' => $grouped,
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
            /** @var AccountEntry|null $match */
            $match = $form->has('match') ? $form->get('match')->getData() : null;
            $others = $match !== null ? ($othersByPayment[$match->getId()] ?? []) : [];
            $unpaid = ReceivedInvoiceConfirmType::awaitsPayment($form);
            $entry->setCreatedBy($this->currentUser());
            $today = new \DateTimeImmutable('today');
            $late = static fn (ReceivedInvoice $i): bool => $i->packageDate() !== null && $closed->closingDeliveryFor($i->packageDate()) !== null;
            $confirmed = $this->confirmOnce($em, $invoices, [$invoice, ...$others], static function () use ($em, $invoice, $entry, $match, $others, $unpaid, $providers, $chosen, $closed, $late, $today): void {
                $provider = $providers->resolve($invoice, $entry, $chosen);
                // El pago queda con la ficha del proveedor: así sale en ella con o sin factura.
                $entry->setProvider($provider);
                if ($match !== null) {
                    // El pago ya estaba en el libro: la factura lo explica, no lo repite.
                    // Si paga también otras del mismo proveedor, van con ella. Un pago de
                    // un trimestre ya entregado no se toca: se entregó tal cual.
                    if ($match->getDate() === null || $closed->closingDeliveryFor($match->getDate()) === null) {
                        $match->completeFrom($entry);
                    }
                    $invoice->confirm($match, $provider);
                    foreach ($others as $other) {
                        $other->confirmAlongside($match, $provider);
                    }
                } elseif ($unpaid) {
                    // Se paga por banco: el pago llegará con el extracto y, al anotarlo,
                    // se engancha a ella. Crearlo aquí lo contaría dos veces.
                    $invoice->acceptUnpaid(
                        $entry->getCategory(),
                        IssuedInvoice::fromCents(-IssuedInvoice::toCents($entry->getAmount())),
                        $provider,
                        $entry->getProviderName(),
                        $entry->getInvoiceNumber(),
                    );
                } else {
                    $invoice->confirm($entry, $provider);
                    $em->persist($entry);
                }
                // La que llega tarde a un trimestre entregado va en el de hoy.
                foreach (array_filter([$invoice, ...$others], $late) as $lateInvoice) {
                    $lateInvoice->bookLate($today);
                }
            });
            if (!$confirmed) {
                $this->addFlash('warning', $others === []
                    ? 'Esta factura ya estaba aceptada: no se ha creado otro apunte.'
                    : 'Alguna de esas facturas ya estaba aceptada: no se ha enganchado ninguna.');

                return $this->redirectToRoute('accounting_invoices');
            }
            $done = match (true) {
                $others !== [] => sprintf('Enganchadas %d facturas al pago que ya estaba en el libro: %s.', \count($others) + 1, $match->getConcept()),
                $match !== null => sprintf('Enganchada al pago que ya estaba en el libro: %s.', $match->getConcept()),
                $unpaid => sprintf('Aceptada, esperando el pago: cuando anotes ese movimiento de %s, se engancha a ella.', $entry->getAccount()?->getName()),
                default => sprintf('Anotado: %s.', $entry->getConcept()),
            };
            if ($invoice->getBookedOn() !== null) {
                $done .= sprintf(
                    ' Su trimestre ya se entregó a la gestoría: va en el paquete del %s.',
                    InvoiceQuarter::containing($invoice->getBookedOn())->label(),
                );
            }

            // Quien confirma suele ir una tras otra: la siguiente de la cola, la que
            // venía detrás de ésta (las que se dejaron atrás, como una aún sin pagar,
            // no vuelven a salir una y otra vez), o la primera si era la última.
            // El aviso lo dice, porque habla de la factura anterior y la pantalla ya
            // enseña otra: sin eso parece que no se ha guardado.
            $queue = $this->reviewQueue($invoices);
            $next = ReviewQueuePosition::of($invoice, $queue)->next ?? $queue[0] ?? null;
            if ($next === null) {
                $this->addFlash('success', $done);

                return $this->redirectToRoute('accounting_invoices');
            }
            $this->addFlash('success', sprintf(
                '%s Ahora tienes delante la siguiente sin validar (%s).',
                $done,
                \count($queue) === 1 ? 'es la última' : sprintf('quedan %d', \count($queue)),
            ));

            return $this->redirectToRoute('accounting_invoice_review', ['id' => $next->getId()]);
        }

        return $this->render('accounting/invoice_review.html.twig', [
            'invoice' => $invoice,
            'twin' => $invoices->findAlreadyConfirmedTwin($invoice),
            'sameFile' => $invoices->findSameFile($invoice),
            'dataWarnings' => $dataWarnings->for($invoice),
            'hasDocument' => $files->pathTo($invoice->getFileName()) !== null,
            'form' => $form->createView(),
            'suggestions' => AccountEntryType::suggestedDirections($categories->findActive()),
            'queue' => ReviewQueuePosition::of($invoice, $this->reviewQueue($invoices)),
        ]);
    }

    /**
     * Las facturas que esperan que alguien las revise: las de la bandeja menos las
     * que aún están en cola para leerse, que no tienen nada que mirar todavía.
     *
     * @return list<ReceivedInvoice>
     */
    private function reviewQueue(ReceivedInvoiceRepository $invoices): array
    {
        return array_values(array_filter(
            $invoices->findOpen(),
            static fn (ReceivedInvoice $open): bool => $open->getStatus() !== ReceivedInvoice::STATUS_PENDING,
        ));
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

    /**
     * Confirma las facturas (la revisada y, en un pago agrupado, las que van con ella)
     * y guarda su apunte, una sola vez aunque lleguen dos peticiones a la vez. Si otra
     * se adelantó con cualquiera de ellas, deshace y no guarda nada: tampoco los
     * cambios del formulario, que pisarían la factura ya confirmada.
     *
     * Transacción a mano y no wrapInTransaction(), que hace flush() siempre al
     * terminar, también cuando no hay que guardar.
     *
     * @param list<ReceivedInvoice> $all   Las que se confirman.
     * @param \Closure              $write Lo que convierte la factura en apunte; el flush va aquí.
     *
     * @return bool False si alguna ya no estaba abierta.
     */
    private function confirmOnce(EntityManagerInterface $em, ReceivedInvoiceRepository $invoices, array $all, \Closure $write): bool
    {
        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            $reserved = array_filter($all, static fn (ReceivedInvoice $i): bool => $invoices->reserveForConfirmation($i));
            if (\count($reserved) !== \count($all)) {
                $connection->rollBack();
                $em->clear();

                return false;
            }
            $write();
            $em->flush();
            $connection->commit();

            return true;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
