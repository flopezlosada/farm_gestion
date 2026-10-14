<?php

namespace App\Controller;

use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\User;
use App\Form\RemittanceImportType;
use App\Form\RemittanceLineType;
use App\Repository\PartnerRepository;
use App\Repository\RemittanceRepository;
use App\Service\Accounting\Invoice\LedgerSheet;
use App\Service\Accounting\Remittance\BasketFeeExpectations;
use App\Service\Accounting\Remittance\MandatesToPartners;
use App\Service\Accounting\Remittance\RemittanceFromFees;
use App\Service\Accounting\Remittance\RemittancePartnerMatcher;
use App\Service\Accounting\Remittance\RemittancePeriodText;
use App\Service\Accounting\Remittance\RemittanceReview;
use App\Service\Accounting\Remittance\RemittanceSheet;
use App\Service\Partner\Fee\MonthlyFeeCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Las remesas de recibos: el Excel que cada mes se sube a Fiare para cobrar las
 * cuotas.
 *
 * El circuito: se importan los Excel ya enviados (histórico), se prepara la remesa
 * siguiente copiando la última, se revisa con los avisos (quién no casa con sus
 * cestas, quién tiene cesta y no recibo), se descarga el Excel y se da por enviada.
 *
 * Ver, el permiso de lectura de contabilidad; todo lo demás, el de edición.
 */
#[Route('/gestion/accounting/remittances')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class RemittanceController extends AbstractController
{
    /** Cuántos meses hay de una remesa a la siguiente, por tipo, para proponer el mes. */
    private const MONTHS_TO_NEXT = [
        Remittance::KIND_BASKET => 1,
        Remittance::KIND_MEMBERSHIP => 6,
        Remittance::KIND_OTHER => 1,
    ];

    /** Todas, y la siguiente que toca preparar de cada tipo. */
    #[Route('', name: 'accounting_remittances', methods: ['GET'])]
    public function index(RemittanceRepository $remittances): Response
    {
        $next = [];
        foreach (array_keys(Remittance::KIND_LABELS) as $kind) {
            $latest = $remittances->findLatestOfKind($kind);
            $next[$kind] = [
                'from' => $latest,
                'period' => $latest !== null
                    ? $latest->getPeriod()->modify(sprintf('+%d months', self::MONTHS_TO_NEXT[$kind]))
                    : Remittance::firstOfMonth(new \DateTimeImmutable('first day of next month')),
            ];
        }

        return $this->render('accounting/remittances.html.twig', [
            'remittances' => $remittances->findAllNewestFirst(),
            'next' => $next,
        ]);
    }

    /**
     * Subir un Excel ya enviado. Entra como enviada y con cada recibo enlazado a su
     * socix cuando la cuenta o el nombre no dejan duda.
     */
    #[Route('/import', name: 'accounting_remittance_import', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function import(Request $request, RemittanceSheet $sheet, RemittancePartnerMatcher $matcher, RemittanceRepository $remittances, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(RemittanceImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            $fileName = $file->getClientOriginalName();
            $kind = (string) $form->get('kind')->getData();
            $period = $form->get('period')->getData() ?? RemittancePeriodText::guessPeriod($fileName);
            $label = trim((string) $form->get('label')->getData()) ?: (string) preg_replace('/^\d+\.\s*|\.\w+$/', '', $fileName);

            $read = $sheet->read($file->getPathname());
            $error = match (true) {
                $period === null => 'El nombre del fichero no dice el mes: escríbelo.',
                $read['problems'] !== [] => 'No se ha importado nada: ' . implode(' ', $read['problems']),
                $read['lines'] === [] => 'El Excel no tiene ningún recibo.',
                $remittances->existsFor($kind, $period) => sprintf('Ya hay una remesa de %s de %s. Si se importó mal, tírala antes.', mb_strtolower(Remittance::KIND_LABELS[$kind]), $period->format('m/Y')),
                default => null,
            };
            if ($error !== null) {
                $this->addFlash('danger', $error);

                return $this->render('accounting/remittance_import.html.twig', ['form' => $form->createView()]);
            }

            $unmatched = $matcher->match($read['lines']);
            $remittance = Remittance::fromImport($kind, $period, trim((string) preg_replace('/\s+/', ' ', $label)), $fileName, $read['lines'], $this->actor());
            $em->persist($remittance);
            $em->flush();

            $this->addFlash('success', sprintf('Importados %d recibos (%s €).', $remittance->getLines()->count(), number_format((float) $remittance->getTotal(), 2, ',', '.')));
            if ($unmatched > 0) {
                $this->addFlash('warning', $unmatched === 1
                    ? '1 recibo no se ha podido enlazar a ningún socix: revísalo en el próximo borrador.'
                    : sprintf('%d recibos no se han podido enlazar a ningún socix: revísalos en el próximo borrador.', $unmatched));
            }

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        }

        return $this->render('accounting/remittance_import.html.twig', ['form' => $form->createView()]);
    }

    /**
     * Preparar la remesa de un mes: copia de la última anterior del mismo tipo, en
     * borrador. Si no hay ninguna anterior, nace vacía.
     */
    #[Route('/prepare', name: 'accounting_remittance_prepare', methods: ['POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function prepare(Request $request, RemittanceRepository $remittances, EntityManagerInterface $em): Response
    {
        $back = $this->redirectToRoute('accounting_remittances');
        if (!$this->isCsrfTokenValid('prepare-remittance', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }

        $kind = (string) $request->request->get('kind');
        $period = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->request->get('period'));
        if (!isset(Remittance::KIND_LABELS[$kind]) || $period === false) {
            $this->addFlash('warning', 'Elige el tipo y el mes.');

            return $back;
        }
        if ($remittances->existsFor($kind, $period)) {
            $this->addFlash('warning', sprintf('Ya hay una remesa de %s de %s.', mb_strtolower(Remittance::KIND_LABELS[$kind]), $period->format('m/Y')));

            return $back;
        }

        $latest = $remittances->findLatestOfKind($kind, $period);
        $remittance = $latest !== null
            ? Remittance::nextFrom($latest, $period, $this->actor())
            : new Remittance($kind, $period, sprintf('%s %s', Remittance::KIND_LABELS[$kind], $period->format('m/Y')), Remittance::SOURCE_APP, $this->actor());
        $em->persist($remittance);
        $em->flush();

        $this->addFlash('success', $latest !== null
            ? sprintf('Borrador preparado con los %d recibos de «%s». Revisa los avisos antes de descargarla.', $remittance->getLines()->count(), $latest->getLabel())
            : 'Borrador vacío: es la primera de su tipo. Añade los recibos uno a uno.');

        return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
    }

    /**
     * Preparar la remesa de cuotas de un mes desde el cálculo de las cuotas: un recibo
     * por cada persona que se puede cobrar, con los datos de su ficha. Sustituye a
     * copiar la del mes anterior, que arrastraba lo que hubiera cambiado.
     */
    #[Route('/from-fees', name: 'accounting_remittance_from_fees', methods: ['POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function fromFees(Request $request, RemittanceRepository $remittances, MonthlyFeeCalculator $calculator, RemittanceFromFees $builder, EntityManagerInterface $em): Response
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->request->get('period'));
        // Se vuelve a la pantalla desde la que se pidió: las cuotas del mes o la lista.
        $back = $request->request->get('from') === 'fees' && $period !== false
            ? $this->redirectToRoute('monthly_fee_index', ['month' => $period->format('Y-m')])
            : $this->redirectToRoute('accounting_remittances');
        if (!$this->isCsrfTokenValid('remittance-from-fees', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }
        if ($period === false) {
            $this->addFlash('warning', 'Elige el mes.');

            return $back;
        }
        $existing = $remittances->findOneBy(['kind' => Remittance::KIND_BASKET, 'period' => Remittance::firstOfMonth($period)]);
        if ($existing !== null) {
            $this->addFlash('warning', sprintf('Ya hay una remesa de cuotas de %s: «%s». Si hay que rehacerla, tira el borrador antes.', $period->format('m/Y'), $existing->getLabel()));

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $existing->getId()]);
        }

        $fees = $calculator->forMonth($period);
        $remittance = $builder->build($fees, $this->actor());
        if ($remittance->getLines()->isEmpty()) {
            $this->addFlash('warning', 'No hay ninguna cuota que se pueda cobrar ese mes: mira lo que falta en «Cuotas del mes».');

            return $back;
        }
        $em->persist($remittance);
        $em->flush();

        $left = \count($fees->getStatements()) - $remittance->getLines()->count();
        $this->addFlash('success', sprintf('Borrador con %d recibos (%s €).', $remittance->getLines()->count(), number_format((float) $remittance->getTotal(), 2, ',', '.')));
        if ($left > 0) {
            $this->addFlash('warning', sprintf('%d %s no se pueden cobrar todavía y no van en la remesa: están en «Cuotas del mes» con el motivo.', $left, $left === 1 ? 'cuota' : 'cuotas'));
        }

        return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
    }

    /** Una remesa: sus recibos, y en un borrador lo que hay que revisar antes de enviarla. */
    #[Route('/{id}', name: 'accounting_remittance_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Remittance $remittance, RemittanceRepository $remittances, RemittanceReview $review, BasketFeeExpectations $fees, ValidatorInterface $validator, MandatesToPartners $transfer, PartnerRepository $partners): Response
    {
        $previous = $remittances->findLatestOfKind($remittance->getKind(), $remittance->getPeriod());
        $remittances->loadLinesWithPartners($remittance, $previous);
        $expected = $remittance->isDraft() && $remittance->isBasketFees() ? $fees->forMonth($remittance->getPeriod()) : [];

        return $this->render('accounting/remittance_show.html.twig', [
            'remittance' => $remittance,
            'previous' => $previous,
            'problems' => $remittance->isDraft() ? $this->sendProblems($remittance, $validator) : [],
            'warnings' => $review->lineWarnings($remittance, $expected),
            'withoutLine' => $review->familiesWithoutLine($remittance, $expected),
            'changes' => $review->changesFrom($previous, $remittance),
            // Una enviada dice con qué cuenta y mandato se cobró: lo que las fichas
            // aún no tienen, o tienen distinto.
            'toPartners' => $remittance->isSent() ? $transfer->plan($remittance, $partners->findWithMandate()) : null,
        ]);
    }

    /** El Excel para subir a Fiare, con las columnas de siempre. */
    #[Route('/{id}/xlsx', name: 'accounting_remittance_xlsx', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function xlsx(Remittance $remittance, RemittanceSheet $sheet): Response
    {
        $book = $sheet->write($remittance);
        $response = new StreamedResponse(static function () use ($book): void {
            (new Xlsx($book))->save('php://output');
        });
        $name = LedgerSheet::safeFileName(sprintf('%s. %s', $remittance->getPeriod()->format('m'), $remittance->getLabel())) . '.xlsx';
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, 'remesa.xlsx'));

        return $response;
    }

    /** Darla por enviada al banco: desde aquí es histórico. */
    #[Route('/{id}/send', name: 'accounting_remittance_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function send(Request $request, Remittance $remittance, ValidatorInterface $validator, EntityManagerInterface $em): Response
    {
        $back = $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        if (!$this->isCsrfTokenValid('send-remittance-' . $remittance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }
        if (!$remittance->isDraft() || $this->sendProblems($remittance, $validator) !== []) {
            $this->addFlash('warning', 'Sólo se da por enviado un borrador sin problemas.');

            return $back;
        }

        $remittance->markSent($this->actor());
        $em->flush();
        $this->addFlash('success', 'Remesa dada por enviada. Ya no se puede cambiar.');

        return $back;
    }

    /**
     * Pasar a las fichas de socixs la cuenta y el mandato con que se les cobró en una
     * remesa enviada. Sólo rellena lo que falta; lo que no coincide lo enseña.
     */
    #[Route('/{id}/to-partners', name: 'accounting_remittance_to_partners', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function toPartners(Request $request, Remittance $remittance, RemittanceRepository $remittances, PartnerRepository $partners, MandatesToPartners $transfer, EntityManagerInterface $em): Response
    {
        $back = $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        if (!$this->isCsrfTokenValid('remittance-to-partners-' . $remittance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }
        if (!$remittance->isSent()) {
            $this->addFlash('warning', 'Sólo se pasan a las fichas los datos de una remesa enviada.');

            return $back;
        }

        $remittances->loadLinesWithPartners($remittance);
        $plan = $transfer->plan($remittance, $partners->findWithMandate());
        $transfer->apply($remittance, $plan);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Fichas actualizadas: %d %s y %d %s. Lo que no coincide sigue abajo, sin cambiar.',
            \count($plan['mandates']), \count($plan['mandates']) === 1 ? 'mandato' : 'mandatos',
            \count($plan['ibans']), \count($plan['ibans']) === 1 ? 'cuenta' : 'cuentas',
        ));

        return $back;
    }

    /** Tirarla: un borrador, o una importada que se subió mal. */
    #[Route('/{id}/delete', name: 'accounting_remittance_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function delete(Request $request, Remittance $remittance, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-remittance-' . $remittance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        }
        if (!$remittance->isDeletable()) {
            $this->addFlash('warning', 'Una remesa enviada desde aquí es el registro de lo que se cobró: no se tira.');

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        }

        $em->remove($remittance);
        $em->flush();
        $this->addFlash('success', 'Remesa tirada.');

        return $this->redirectToRoute('accounting_remittances');
    }

    /**
     * Añadir un recibo a un borrador. Desde la lista de «con cesta y sin recibo» llega
     * con el socix: se rellena con su nombre, su cuenta, lo que suman sus cestas y el
     * siguiente número de mandato libre.
     */
    #[Route('/{id}/lines/new', name: 'accounting_remittance_line_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function lineNew(Request $request, Remittance $remittance, RemittanceRepository $remittances, BasketFeeExpectations $fees, EntityManagerInterface $em): Response
    {
        if (!$remittance->isDraft()) {
            return $this->sentRedirect($remittance);
        }

        $line = new RemittanceLine();
        if (!$request->isMethod('POST')) {
            $this->prefill($line, $remittance, $request->query->getInt('partner'), $remittances, $fees, $em);
        }

        return $this->handleLine($request, $remittance, $line, $em, 'Recibo añadido.');
    }

    #[Route('/lines/{id}/edit', name: 'accounting_remittance_line_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function lineEdit(Request $request, RemittanceLine $line, EntityManagerInterface $em): Response
    {
        $remittance = $line->getRemittance();
        if (!$remittance->isDraft()) {
            return $this->sentRedirect($remittance);
        }

        return $this->handleLine($request, $remittance, $line, $em, 'Recibo guardado.');
    }

    #[Route('/lines/{id}/delete', name: 'accounting_remittance_line_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function lineDelete(Request $request, RemittanceLine $line, EntityManagerInterface $em): Response
    {
        $remittance = $line->getRemittance();
        if (!$remittance->isDraft()) {
            return $this->sentRedirect($remittance);
        }
        if ($this->isCsrfTokenValid('delete-remittance-line-' . $line->getId(), (string) $request->request->get('_token'))) {
            $remittance->removeLine($line);
            $em->flush();
            $this->addFlash('success', sprintf('Recibo de %s quitado de la remesa.', $line->getDebtorName()));
        } else {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');
        }

        return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
    }

    /**
     * Lo que impide enviarla: lo que comprueba la remesa más la validez de cada IBAN,
     * que necesita el validador.
     *
     * @return list<string>
     */
    private function sendProblems(Remittance $remittance, ValidatorInterface $validator): array
    {
        $ibans = [];
        foreach ($remittance->getLines() as $line) {
            foreach ($validator->validateProperty($line, 'iban') as $violation) {
                /** @var ConstraintViolationInterface $violation */
                $ibans[] = sprintf('%s: %s', $line->getDebtorName(), lcfirst((string) $violation->getMessage()));
            }
        }

        return array_values(array_unique([...$remittance->sendProblems(), ...$ibans]));
    }

    /** Alta y edición de un recibo son el mismo formulario. */
    private function handleLine(Request $request, Remittance $remittance, RemittanceLine $line, EntityManagerInterface $em, string $done): Response
    {
        $form = $this->createForm(RemittanceLineType::class, $line);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $remittance->addLine($line);
            $em->flush();
            $this->addFlash('success', $done);

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        }

        return $this->render('accounting/remittance_line_form.html.twig', [
            'form' => $form->createView(),
            'remittance' => $remittance,
            'line' => $line,
        ]);
    }

    /**
     * Rellena un recibo nuevo: el mandato nuevo lleva el siguiente número libre con el
     * prefijo de la remesa, y es su primer adeudo (FRST). Con socix, además su nombre,
     * su cuenta y lo que suman sus cestas.
     */
    private function prefill(RemittanceLine $line, Remittance $remittance, int $partnerId, RemittanceRepository $remittances, BasketFeeExpectations $fees, EntityManagerInterface $em): void
    {
        $sample = $remittance->getLines()->first() ?: null;
        $prefix = $sample !== null ? rtrim((string) preg_replace('/\d+\s*$/', '', $sample->getMandateReference())) : '';
        $mandate = $remittances->nextMandateReference($prefix !== '' ? $prefix : RemittanceRepository::DEFAULT_MANDATE_PREFIX);

        $line->setMandateReference($mandate)
            ->setMandateSignedOn(new \DateTimeImmutable('today'))
            ->setSequenceType(RemittanceLine::SEQUENCE_FIRST)
            ->setDebitReference(mb_substr($mandate . ' ' . $remittance->getPeriod()->format('Ym'), 0, RemittanceLine::MAX_REFERENCE));

        $partner = $partnerId > 0 ? $em->find(Partner::class, $partnerId) : null;
        if ($partner === null) {
            return;
        }
        $fee = $fees->forMonth($remittance->getPeriod())[$partner->getFamilyHead()->getId()] ?? null;
        $line->setPartner($partner)
            ->setDebtorName(mb_strtoupper($partner->getLegalName()))
            ->setIban($partner->getIban())
            ->setAmount($fee !== null ? IssuedInvoice::fromCents($fee['cents']) : '0');
    }

    private function sentRedirect(Remittance $remittance): Response
    {
        $this->addFlash('warning', 'Una remesa enviada no se cambia.');

        return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
