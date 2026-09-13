<?php

namespace App\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategoryGroup;
use App\Entity\User;
use App\Form\AccountEntryType;
use App\Form\AccountTransferType;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryRepository;
use App\Repository\BudgetRepository;
use App\Repository\FinancialAccountRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Accounting\BudgetTracker;
use App\Service\Accounting\TransferRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Las cuentas de la asociación: el libro de caja y el seguimiento del presupuesto.
 *
 * La portada es «Cómo vamos» y no el listado de apuntes a propósito: la pregunta que
 * trae a alguien aquí es si el año cuadra, no qué se pagó el martes. El libro está a
 * un clic para quien viene a anotar.
 *
 * Acceso con ROLE_GESTION_CONTABILIDAD, que no da acceso a los datos de socixs
 * (mínimo privilegio): aquí se manejan importes y partidas, no personas.
 */
#[Route('/gestion/accounting')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class AccountingController extends AbstractController
{
    /** Nombres de los meses, para cabeceras de tabla y títulos. */
    private const MONTHS = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * Cómo va el año: lo previsto contra lo real por grupo, la caja mes a mes con su
     * proyección a cierre, y cuánto tendría que costar la cesta para que cuadre.
     */
    #[Route('/', name: 'accounting_index', methods: ['GET'])]
    public function index(
        Request $request,
        BudgetTracker $tracker,
        BudgetRepository $budgets,
        FinancialAccountRepository $accounts,
        AccountEntryRepository $entries,
        PartnerBasketShareRepository $shares,
    ): Response {
        $year = $request->query->getInt('year') ?: (int) date('Y');
        $budget = $this->resolveBudget($request, $budgets, $year);

        $balances = [];
        $totalCash = 0.0;
        foreach ($accounts->findActive() as $account) {
            $balance = $entries->balanceFor($account);
            $balances[] = ['account' => $account, 'balance' => $balance];
            $totalCash += (float) $balance;
        }

        $baskets = $shares->countActiveBasketEquivalents();

        return $this->render('accounting/index.html.twig', [
            'year' => $year,
            'years' => $this->yearsWithData($budgets, $entries),
            'budget' => $budget,
            'budgets' => $budgets->findForYear($year),
            'tracked' => $tracker->track($year, $budget),
            'cash' => $tracker->cashFlow($year, $budget),
            'basketCost' => $tracker->basketCost($year, $budget, $baskets, 100.0),
            'baskets' => $baskets,
            'committedMonthlyFees' => $shares->sumActiveMonthlyFees(),
            'balances' => $balances,
            'totalCash' => $totalCash,
            'unpairedTransfers' => $entries->findUnpairedTransfers(),
            'months' => self::MONTHS,
            'kinds' => BudgetCategoryGroup::KIND_LABELS,
        ]);
    }

    /**
     * El libro: todos los apuntes, filtrables y paginados. Es la pantalla de quien
     * viene con el extracto delante.
     */
    #[Route('/ledger', name: 'accounting_ledger', methods: ['GET'])]
    public function ledger(
        Request $request,
        AccountEntryRepository $entries,
        FinancialAccountRepository $accounts,
        BudgetCategoryRepository $categories,
        PaginatorInterface $paginator,
    ): Response {
        $accountId = $request->query->getInt('account');
        $account = $accountId > 0 ? $accounts->find($accountId) : null;

        $filters = [
            'account' => $account,
            'category' => $request->query->getInt('category') ?: null,
            'group' => $request->query->getInt('group') ?: null,
            'from' => $this->parseDate($request->query->get('from')),
            'to' => $this->parseDate($request->query->get('to')),
            'text' => trim((string) $request->query->get('q')) ?: null,
        ];

        $qb = $entries->listingQueryBuilder($filters);

        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            50,
            ['defaultSortFieldName' => 'e.date', 'defaultSortDirection' => 'desc']
        );

        return $this->render('accounting/ledger.html.twig', [
            'pagination' => $pagination,
            'accounts' => $accounts->findActive(),
            'categories' => $categories->findActive(),
            'filters' => $filters,
            'account' => $account,
            'query' => $request->query->all(),
            'balance' => $account !== null ? $entries->balanceFor($account) : null,
        ]);
    }

    /**
     * Un mes: sus apuntes agrupados por partida, con el saldo de cada cuenta al
     * cierre. Es la hoja mensual del Excel, pero sin teclearla.
     */
    #[Route('/month/{year}/{month}', name: 'accounting_month', requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET'])]
    public function month(
        int $year,
        int $month,
        AccountEntryRepository $entries,
        FinancialAccountRepository $accounts,
    ): Response {
        $month = max(1, min(12, $month));
        $lastDay = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastDay = $lastDay->modify('last day of this month');

        $grouped = [];
        $totals = [];
        foreach ($entries->findForMonth($year, $month) as $entry) {
            $category = $entry->getCategory();
            $group = $category?->getGroup();
            if ($category === null || $group === null) {
                continue;
            }
            $groupKey = (int) $group->getId();
            $categoryKey = (int) $category->getId();

            $grouped[$groupKey]['group'] ??= $group;
            $grouped[$groupKey]['categories'][$categoryKey]['category'] ??= $category;
            $grouped[$groupKey]['categories'][$categoryKey]['entries'][] = $entry;
            $grouped[$groupKey]['categories'][$categoryKey]['total'] =
                ($grouped[$groupKey]['categories'][$categoryKey]['total'] ?? 0) + (float) $entry->getAmount();
            $grouped[$groupKey]['total'] = ($grouped[$groupKey]['total'] ?? 0) + (float) $entry->getAmount();

            $kind = $group->getKind();
            $totals[$kind] = ($totals[$kind] ?? 0) + (float) $entry->getAmount();
        }

        $balances = [];
        foreach ($accounts->findActive() as $account) {
            $balances[] = ['account' => $account, 'balance' => $entries->balanceFor($account, $lastDay)];
        }

        return $this->render('accounting/month.html.twig', [
            'year' => $year,
            'month' => $month,
            'monthName' => self::MONTHS[$month],
            'months' => self::MONTHS,
            'grouped' => $grouped,
            'totals' => $totals,
            'kinds' => BudgetCategoryGroup::KIND_LABELS,
            'balances' => $balances,
        ]);
    }

    /**
     * El año entero en una rejilla de partida por mes. Es la hoja «Resumen» del
     * Excel, calculada en vez de copiada.
     */
    #[Route('/year/{year}', name: 'accounting_year', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function year(
        int $year,
        BudgetTracker $tracker,
        BudgetRepository $budgets,
        AccountEntryRepository $entries,
    ): Response {
        $budget = $budgets->findApprovedForYear($year);

        return $this->render('accounting/year.html.twig', [
            'year' => $year,
            'years' => $this->yearsWithData($budgets, $entries),
            'tracked' => $tracker->track($year, $budget, 12),
            'months' => self::MONTHS,
            'kinds' => BudgetCategoryGroup::KIND_LABELS,
        ]);
    }

    /**
     * Anotar un movimiento. Preselecciona cuenta y fecha si vienen en la URL, que es
     * como llega quien acaba de guardar otro apunte del mismo extracto.
     */
    #[Route('/entry/new', name: 'accounting_entry_new', methods: ['GET', 'POST'])]
    public function entryNew(
        Request $request,
        EntityManagerInterface $em,
        FinancialAccountRepository $accounts,
        BudgetCategoryRepository $categories,
    ): Response {
        $entry = new AccountEntry();
        $entry->setDate($this->parseDate($request->query->get('date')) ?? new \DateTimeImmutable('today'));
        $accountId = $request->query->getInt('account');
        if ($accountId > 0) {
            $entry->setAccount($accounts->find($accountId));
        }

        $form = $this->createForm(AccountEntryType::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entry->setCreatedBy($this->currentUser());
            $em->persist($entry);
            $em->flush();
            $this->addFlash('success', sprintf('Anotado: %s.', $entry->getConcept()));

            if ($form->get('submitAndNew')->isClicked()) {
                return $this->redirectToRoute('accounting_entry_new', [
                    'account' => $entry->getAccount()?->getId(),
                    'date' => $entry->getDate()?->format('Y-m-d'),
                ]);
            }

            return $this->redirectToRoute('accounting_ledger', ['account' => $entry->getAccount()?->getId()]);
        }

        return $this->render('accounting/entry_form.html.twig', [
            'form' => $form->createView(),
            'entry' => null,
            'heading' => 'Anotar un apunte',
            'lead' => 'Un movimiento de una cuenta. El saldo se recalcula solo.',
            'suggestions' => AccountEntryType::suggestedDirections($categories->findActive()),
        ]);
    }

    /**
     * Corregir un apunte ya anotado.
     *
     * Las mitades de un traspaso no pasan por aquí: cambiarle el importe a una sola
     * dejaría el saldo de la otra cuenta mal. Se borran (las dos a la vez) y se
     * vuelven a anotar.
     */
    #[Route('/entry/{id}/edit', name: 'accounting_entry_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function entryEdit(
        Request $request,
        AccountEntry $entry,
        EntityManagerInterface $em,
        BudgetCategoryRepository $categories,
    ): Response {
        if ($entry->isTransfer()) {
            $this->addFlash('warning', 'Un traspaso no se edita por mitades: bórralo y vuelve a anotarlo.');

            return $this->redirectToRoute('accounting_ledger');
        }

        $form = $this->createForm(AccountEntryType::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Apunte corregido.');

            return $this->redirectToRoute('accounting_ledger', ['account' => $entry->getAccount()?->getId()]);
        }

        return $this->render('accounting/entry_form.html.twig', [
            'form' => $form->createView(),
            'entry' => $entry,
            'heading' => 'Corregir el apunte',
            'lead' => 'Lo que cambies aquí cambia el saldo de la cuenta y el seguimiento del presupuesto.',
            'suggestions' => AccountEntryType::suggestedDirections($categories->findActive()),
        ]);
    }

    /**
     * Borrar un apunte. Si es un traspaso, se van las dos mitades: dejar una sola
     * descuadra la cuenta contraria.
     */
    #[Route('/entry/{id}/delete', name: 'accounting_entry_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function entryDelete(
        Request $request,
        AccountEntry $entry,
        EntityManagerInterface $em,
        TransferRecorder $transfers,
    ): Response {
        $accountId = $entry->getAccount()?->getId();

        if (!$this->isCsrfTokenValid('accounting_entry_delete_'.$entry->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_ledger', ['account' => $accountId]);
        }

        if ($entry->isTransfer()) {
            $transfers->remove($entry);
            $this->addFlash('success', 'Traspaso borrado, con sus dos mitades.');
        } else {
            $em->remove($entry);
            $this->addFlash('success', 'Apunte borrado.');
        }
        $em->flush();

        return $this->redirectToRoute('accounting_ledger', ['account' => $accountId]);
    }

    /**
     * Mover dinero de una cuenta propia a otra. Son dos apuntes, pero se teclean como
     * un solo hecho para que no puedan quedarse descuadrados.
     */
    #[Route('/transfer/new', name: 'accounting_transfer_new', methods: ['GET', 'POST'])]
    public function transferNew(Request $request, EntityManagerInterface $em, TransferRecorder $transfers): Response
    {
        $form = $this->createForm(AccountTransferType::class, [
            'date' => new \DateTimeImmutable('today'),
            'from' => null,
            'to' => null,
            'amount' => null,
            'concept' => null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $transfers->record(
                $data['from'],
                $data['to'],
                (string) $data['amount'],
                $data['date'],
                trim((string) $data['concept']),
                $this->currentUser(),
            );
            $em->flush();
            $this->addFlash('success', sprintf(
                'Traspaso anotado: %s € de %s a %s.',
                number_format((float) $data['amount'], 2, ',', '.'),
                $data['from']->getName(),
                $data['to']->getName(),
            ));

            return $this->redirectToRoute('accounting_ledger');
        }

        return $this->render('accounting/transfer_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /** Quien está anotando, para dejar rastro de quién metió cada apunte. */
    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * El presupuesto contra el que medir: el que venga en la URL si es de ese año, y
     * si no el aprobado por la asamblea.
     */
    private function resolveBudget(Request $request, BudgetRepository $budgets, int $year): ?object
    {
        $budgetId = $request->query->getInt('budget');
        if ($budgetId > 0) {
            $budget = $budgets->find($budgetId);
            if ($budget !== null && $budget->getYear() === $year) {
                return $budget;
            }
        }

        return $budgets->findApprovedForYear($year);
    }

    /**
     * Años que tienen presupuesto o apuntes, para el selector. Si no hay nada, el
     * año en curso, para que el selector no salga vacío.
     *
     * @return int[]
     */
    private function yearsWithData(BudgetRepository $budgets, AccountEntryRepository $entries): array
    {
        $years = $budgets->findYears();

        $rows = $entries->createQueryBuilder('e')
            ->select('DISTINCT YEAR(e.date) AS y')
            ->orderBy('y', 'DESC')
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as $row) {
            $years[] = (int) $row['y'];
        }

        $years = array_values(array_unique($years));
        rsort($years);

        return $years !== [] ? $years : [(int) date('Y')];
    }

    /** Fecha de un filtro de la URL, o null si viene vacía o ilegible. */
    private function parseDate(mixed $raw): ?\DateTimeInterface
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d|', $value);

        return $date !== false ? $date : null;
    }
}
