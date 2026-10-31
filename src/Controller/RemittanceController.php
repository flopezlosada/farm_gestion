<?php

namespace App\Controller;

use App\Entity\AccountEntry;
use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\User;
use App\Form\RemittanceImportType;
use App\Form\RemittanceLineType;
use App\Repository\PartnerRepository;
use App\Repository\RemittanceRepository;
use App\Repository\RemittanceReturnRepository;
use App\Service\Accounting\Invoice\LedgerSheet;
use App\Service\Accounting\Remittance\BankReturns;
use App\Service\Accounting\Remittance\BasketFeeExpectations;
use App\Service\Accounting\Remittance\CaseAction;
use App\Service\Accounting\Remittance\FlatRemittance;
use App\Service\Accounting\Remittance\MandatesToPartners;
use App\Service\Accounting\Remittance\PartnerNameMatcher;
use App\Service\Accounting\Remittance\RemittanceBase;
use App\Service\Accounting\Remittance\RemittanceCases;
use App\Service\Accounting\Remittance\RemittanceChargeWatch;
use App\Service\Accounting\Remittance\RemittanceFromFees;
use App\Service\Accounting\Remittance\RemittancePartnerMatcher;
use App\Service\Accounting\Remittance\RemittancePeriodText;
use App\Service\Accounting\Remittance\RemittanceReview;
use App\Service\Accounting\Remittance\RemittanceSheet;
use App\Service\Partner\Fee\FeeStatement;
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

    /**
     * Cuántos días antes o después de hoy se admite un día de cargo: más lejos es una
     * errata (el año mal puesto) y la remesa se vigilaría en una fecha que no es.
     */
    private const CHARGE_DATE_RANGE_DAYS = 90;

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
            'kindLabels' => Remittance::KIND_LABELS,
            'bases' => RemittanceBase::cases(),
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
     * borrador. Si no hay ninguna anterior, nace vacía. La de cuotas de cesta no se
     * copia: sale del cálculo, en fromFees().
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
        if ($kind === Remittance::KIND_BASKET) {
            // Copiar la anterior arrastraría altas, bajas y cambios de cesta sin
            // aplicar: la de cuotas de cesta sólo sale del cálculo (fromFees).
            $this->addFlash('warning', 'La remesa de cuotas de cesta se prepara desde las cuotas del mes.');

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
     * Preparar una remesa del mismo importe para toda una base: una derrama o la cuota
     * de socix. La base se elige: todxs lxs socixs, o quien paga cesta ese mes (del
     * cálculo de las cuotas, que ya sabe quién paga una cesta compartida). Sale en
     * borrador: se quita a quien no toque antes de enviarla.
     */
    #[Route('/flat', name: 'accounting_remittance_flat', methods: ['POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function flat(Request $request, RemittanceRepository $remittances, PartnerRepository $partners, MonthlyFeeCalculator $calculator, FlatRemittance $builder, EntityManagerInterface $em): Response
    {
        $back = $this->redirectToRoute('accounting_remittances');
        if (!$this->isCsrfTokenValid('prepare-remittance', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }

        $kind = (string) $request->request->get('kind');
        $period = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->request->get('period'));
        $base = RemittanceBase::tryFrom((string) $request->request->get('base'));
        $rawAmount = str_replace(',', '.', trim((string) $request->request->get('amount')));
        if (!\in_array($kind, [Remittance::KIND_MEMBERSHIP, Remittance::KIND_OTHER], true) || $period === false || $base === null) {
            $this->addFlash('warning', 'Elige el tipo, el mes y a quién.');

            return $back;
        }
        if (preg_match('/^\d{1,5}(\.\d{1,2})?$/', $rawAmount) !== 1 || (float) $rawAmount <= 0) {
            $this->addFlash('warning', 'Pon el importe de cada recibo, en euros (por ejemplo 20 o 12,50).');

            return $back;
        }
        if ($remittances->existsFor($kind, $period)) {
            $this->addFlash('warning', sprintf('Ya hay una remesa de %s de %s.', mb_strtolower(Remittance::KIND_LABELS[$kind]), $period->format('m/Y')));

            return $back;
        }

        $payers = match ($base) {
            RemittanceBase::Members => $partners->findMembers(),
            RemittanceBase::BasketPayers => array_map(static fn (FeeStatement $s): Partner => $s->payer, $calculator->forMonth($period)->getStatements()),
        };
        $concept = trim((string) $request->request->get('label'));
        $label = mb_substr($concept !== '' ? $concept : sprintf('%s %s', Remittance::KIND_LABELS[$kind], $period->format('m/Y')), 0, 255);
        $remittance = $builder->build($kind, Remittance::firstOfMonth($period), $label, number_format((float) $rawAmount, 2, '.', ''), $payers, $this->actor());
        if ($remittance->getLines()->isEmpty()) {
            $this->addFlash('warning', sprintf('Nadie de «%s» tiene el mandato completo: no hay a quién pasarle el recibo.', $base->label()));

            return $back;
        }
        $em->persist($remittance);
        $em->flush();

        $this->addFlash('success', sprintf('Borrador para «%s»: %d recibos (%s €).', $base->label(), $remittance->getLines()->count(), number_format((float) $remittance->getTotal(), 2, ',', '.')));
        $left = \count(array_unique(array_map(spl_object_id(...), $payers))) - $remittance->getLines()->count();
        if ($left > 0) {
            $this->addFlash('warning', sprintf('%d %s sin mandato completo y no %s en la remesa.', $left, $left === 1 ? 'persona está' : 'personas están', $left === 1 ? 'va' : 'van'));
        }

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
    public function show(Remittance $remittance, RemittanceRepository $remittances, RemittanceReview $review, BasketFeeExpectations $fees, ValidatorInterface $validator, RemittanceCases $cases, PartnerRepository $partners, RemittanceReturnRepository $returnRepository, BankReturns $bankReturns, RemittanceChargeWatch $chargeWatch): Response
    {
        $everyone = $remittance->isSent() ? $partners->findBy([], ['surname' => 'ASC', 'name' => 'ASC']) : [];

        $previous = $remittances->findLatestOfKind($remittance->getKind(), $remittance->getPeriod());
        $remittances->loadLinesWithPartners($remittance, $previous);
        $expected = $remittance->isDraft() && $remittance->isBasketFees() ? $fees->forMonth($remittance->getPeriod()) : [];
        $returns = $remittance->isSent() ? $returnRepository->findByRemittance($remittance) : [];

        return $this->render('accounting/remittance_show.html.twig', [
            'remittance' => $remittance,
            'previous' => $previous,
            'problems' => $remittance->isDraft() ? $this->sendProblems($remittance, $validator) : [],
            'warnings' => $review->lineWarnings($remittance, $expected),
            'withoutLine' => $review->familiesWithoutLine($remittance, $expected),
            'changes' => $review->changesFrom($previous, $remittance),
            // Una enviada dice con qué cuenta y mandato se cobró: cada recibo que no
            // casa con su ficha, para resolverlo a mano, y todas las fichas para
            // enlazar el que no tiene.
            'cases' => $remittance->isSent() ? $cases->build($remittance, $partners->findWithMandate(), $everyone) : null,
            'everyone' => $everyone,
            // Enviada: sus devoluciones, las del banco aún sin recibo con los que
            // pueden ser, y los recibos que se pueden dar por devueltos sin apunte.
            'returns' => $returns,
            'bankReturns' => $remittance->isSent() ? array_map(
                static fn (AccountEntry $e): array => ['entry' => $e, 'candidates' => BankReturns::candidatesFor($e, $remittance, $returns)],
                $bankReturns->unlinkedFor($remittance),
            ) : [],
            'notReturned' => $remittance->isSent() ? BankReturns::notReturned($remittance, $returns) : [],
            // Borrador: lo devuelto que espera a volver a cobrarse.
            'toRecharge' => $remittance->isDraft() ? $returnRepository->findToRecharge() : [],
            // Borrador: el día de cargo que se propone al darla por enviada.
            'usualChargeDate' => $remittance->isDraft() ? $chargeWatch->usualChargeDate(new \DateTimeImmutable('today')) : null,
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
        $chargeDate = self::chargeDate((string) $request->request->get('charge_date'));
        if ($chargeDate === null) {
            $this->addFlash('warning', 'Falta el día de cargo que se pidió al banco, o no es una fecha válida.');

            return $back;
        }

        $remittance->markSent($this->actor(), $chargeDate);
        $em->flush();
        $this->addFlash('success', sprintf('Remesa dada por enviada, con cargo el %s. Ya no se puede cambiar.', $chargeDate->format('d/m/Y')));

        return $back;
    }

    /**
     * Cambiar el día de cargo de una remesa enviada (se rechazó y se volvió a subir, o
     * es una importada que se quiere vigilar), o quitarlo para dejar de vigilarla.
     */
    #[Route('/{id}/charge-date', name: 'accounting_remittance_charge_date', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function chargeDateChange(Request $request, Remittance $remittance, EntityManagerInterface $em): Response
    {
        $back = $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        if (!$this->isCsrfTokenValid('charge-date-' . $remittance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }
        if (!$remittance->isSent()) {
            $this->addFlash('warning', 'La fecha de cargo se pone al darla por enviada.');

            return $back;
        }
        $raw = trim((string) $request->request->get('charge_date'));
        $chargeDate = self::chargeDate($raw);
        if ($raw !== '' && $chargeDate === null) {
            $this->addFlash('warning', 'El día de cargo no es una fecha válida.');

            return $back;
        }

        $remittance->rescheduleCharge($chargeDate);
        $em->flush();
        $this->addFlash('success', $chargeDate !== null
            ? sprintf('Día de cargo cambiado al %s: se vigila que entre en el banco.', $chargeDate->format('d/m/Y'))
            : 'Quitado el día de cargo: ya no se vigila si entra en el banco.');

        return $back;
    }

    /**
     * Resolver a mano un recibo de una remesa enviada que no casa con la ficha de su
     * socix ({@see RemittanceCases}): enlazarlo, poner en la ficha lo del recibo,
     * corregir su nombre o dejarlo como está. Uno cada vez: lo decide una persona.
     */
    #[Route('/lines/{id}/resolve', name: 'accounting_remittance_line_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function resolve(Request $request, RemittanceLine $line, RemittanceRepository $remittances, PartnerRepository $partners, MandatesToPartners $transfer, EntityManagerInterface $em): Response
    {
        $remittance = $line->getRemittance();
        $back = $this->redirect($this->generateUrl('accounting_remittance_show', ['id' => $remittance->getId()]) . '#casos');
        $action = CaseAction::tryFrom((string) $request->request->get('do'));
        if (!$this->isCsrfTokenValid('resolve-line-' . $line->getId(), (string) $request->request->get('_token')) || $action === null) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }
        if (!$remittance->isSent()) {
            $this->addFlash('warning', 'Los recibos de un borrador se cambian en el propio recibo.');

            return $back;
        }
        if ($action->touchesPartner() && !$this->isGranted('ROLE_GESTION_SOCIXS_EDIT')) {
            $this->addFlash('warning', 'Cambiar la ficha de un socix pide permiso de edición de socixs.');

            return $back;
        }

        $who = $line->getDebtorName();
        switch ($action) {
            case CaseAction::Link:
            case CaseAction::LinkAndRename:
                $partner = $partners->find($request->request->getInt('partner'));
                if ($partner === null) {
                    $this->addFlash('warning', 'Elige la ficha de quien paga este recibo.');

                    return $back;
                }
                foreach (RemittanceCases::siblings($remittance, $line) as $sibling) {
                    $sibling->setPartner($partner);
                }
                // El nombre nuevo lo calcula el servidor, no viene del formulario: es
                // el mismo que se enseñó, sin poder cambiarlo por el camino.
                $rename = $action === CaseAction::LinkAndRename
                    ? (PartnerNameMatcher::candidates($who, [$partner])[0]['rename'] ?? null)
                    : null;
                if ($rename !== null) {
                    $partner->setName($rename['name'])->setSurname($rename['surname']);
                }
                $message = $rename !== null
                    ? sprintf('«%s» enlazado con su ficha, que ahora se llama %s %s.', $who, $rename['name'], $rename['surname'])
                    : sprintf('«%s» enlazado con la ficha de %s.', $who, $partner->getLegalName());
                break;
            case CaseAction::Unlink:
                $line->setPartner(null);
                $message = sprintf('«%s» ya no está enlazado con ninguna ficha.', $who);
                break;
            case CaseAction::TakeReceipt:
                $remittances->loadLinesWithPartners($remittance);
                $part = $transfer->forLine($transfer->plan($remittance, $partners->findWithMandate()), $line);
                if ($part === ['mandates' => [], 'ibans' => [], 'repairs' => []]) {
                    $this->addFlash('warning', 'No hay nada del recibo que se pueda poner en la ficha.');

                    return $back;
                }
                $transfer->apply($remittance, $part);
                $message = sprintf('Puesto en la ficha de %s lo del recibo: %s.', $line->getPartner()?->getLegalName(), implode(' y ', array_filter([
                    $part['mandates'] !== [] ? 'el mandato' : null,
                    $part['ibans'] !== [] || $part['repairs'] !== [] ? 'la cuenta' : null,
                ])));
                break;
            case CaseAction::Keep:
                foreach (RemittanceCases::siblings($remittance, $line) as $sibling) {
                    $sibling->setReviewedAt(new \DateTimeImmutable());
                }
                $message = sprintf('«%s» se queda como está.', $who);
                break;
            case CaseAction::Reopen:
                foreach (RemittanceCases::siblings($remittance, $line) as $sibling) {
                    $sibling->setReviewedAt(null);
                }
                $message = sprintf('«%s» vuelve a la lista para revisarlo.', $who);
                break;
        }
        $em->flush();
        $this->addFlash('success', $message);

        return $back;
    }

    /** Tirarla: un borrador, o una importada que se subió mal. */
    #[Route('/{id}/delete', name: 'accounting_remittance_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function delete(Request $request, Remittance $remittance, RemittanceReturnRepository $returns, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-remittance-' . $remittance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        }
        if (!$remittance->isDeletable()) {
            $this->addFlash('warning', 'Una remesa enviada desde aquí es el registro de lo que se cobró: no se tira.');

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        }
        if ($remittance->isSent() && $returns->findByRemittance($remittance) !== []) {
            // Tirarla se llevaría sus devoluciones y lo decidido con cada una.
            $this->addFlash('warning', 'Tiene recibos dados por devueltos: quita antes esas devoluciones.');

            return $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        }

        $em->remove($remittance);
        $em->flush();
        $this->addFlash('success', 'Remesa borrada.');

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

    /**
     * El día de cargo de un formulario, «2026-11-18». Null si no viene, no es una fecha
     * que exista (el 31 de febrero no se convierte en marzo) o está a más de
     * {@see CHARGE_DATE_RANGE_DAYS} días de hoy.
     */
    private static function chargeDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return null;
        }
        $today = new \DateTimeImmutable('today');
        $range = sprintf('%d days', self::CHARGE_DATE_RANGE_DAYS);

        return $date >= $today->modify('-' . $range) && $date <= $today->modify('+' . $range) ? $date : null;
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
