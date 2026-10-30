<?php

namespace App\Controller;

use App\Entity\AccountEntry;
use App\Entity\Budget;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Entity\User;
use App\Form\AccountEntryType;
use App\Form\AccountTransferType;
use App\Http\QueryId;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryRepository;
use App\Repository\BudgetRepository;
use App\Repository\FinancialAccountRepository;
use App\Repository\IssuedInvoiceRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Agenda\AccountingAgenda;
use App\Service\Accounting\Amount;
use App\Service\Accounting\BudgetTracker;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\MonthNames;
use App\Service\Accounting\TransferRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Las cuentas de la asociación: el libro de caja y el seguimiento del presupuesto.
 *
 * La portada es «Pendiente»: lo que hay que hacer para que el libro esté al día y el
 * paquete de la gestoría salga completo. «Cómo vamos» es un informe más.
 *
 * Acceso con ROLE_GESTION_CONTABILIDAD, que no da acceso a los datos de socixs
 * (mínimo privilegio): aquí se manejan importes y partidas, no personas.
 */
#[Route('/gestion/accounting')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class AccountingController extends AbstractController
{
    /**
     * Días alrededor de un traspaso suelto en que se busca la otra mitad. Más que los
     * del emparejado automático: aquí decide una persona viendo los candidatos.
     */
    private const PEER_SEARCH_DAYS = 15;

    /**
     * La portada: lo que hay que hacer. Arriba, el dinero que hay, hasta dónde está el
     * libro al día y cómo va el año frente al presupuesto; debajo, las tareas
     * pendientes de {@see AccountingAgenda}, cada una con su enlace.
     */
    #[Route('/', name: 'accounting_index', methods: ['GET'])]
    public function index(
        AccountingAgenda $agenda,
        BudgetTracker $tracker,
        BudgetRepository $budgets,
        FinancialAccountRepository $accounts,
        AccountEntryRepository $entries,
    ): Response {
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $lastDates = $entries->lastDateByAccount();

        $balances = [];
        foreach ($accounts->findActive() as $account) {
            $last = $lastDates[(int) $account->getId()] ?? null;
            $balances[] = [
                'account' => $account,
                'balance' => $entries->balanceFor($account),
                'lastDate' => $last,
                'stale' => $account->isBank() && $last !== null && AccountingAgenda::isStaleBook($last, $today),
            ];
        }
        $bookUpTo = $entries->bankBookUpTo();
        $budget = $budgets->findReferenceForYear($year);
        $complete = $tracker->track($year, $budget, $tracker->lastCompleteMonth($year, $bookUpTo));
        $booked = $tracker->track($year, $budget);

        return $this->render('accounting/index.html.twig', [
            'year' => $year,
            'items' => $agenda->items($today),
            'balances' => $balances,
            'totalCash' => array_sum(array_map(static fn (array $row): float => (float) $row['balance'], $balances)),
            'bookUpTo' => $bookUpTo,
            'staleBook' => array_filter($balances, static fn (array $row): bool => $row['stale']) !== [],
            'complete' => self::summary($tracker, $complete, $budget),
            'booked' => self::summary($tracker, $booked, $budget),
            'hasBudget' => $budget !== null,
            'months' => MonthNames::LONG,
            'unpairedTransfers' => $entries->findUnpairedTransfers(),
        ]);
    }

    /**
     * Lo que enseña la portada de un corte del libro: hasta qué mes llega, el resultado
     * real y el previsto hasta ahí, y con cuánto dinero acabaría el año si desde ese
     * mes se cumple el presupuesto.
     *
     * @param array<string, mixed> $tracked {@see BudgetTracker::track}
     *
     * @return array{throughMonth: int, actual: float, budget: float, endOfYear: float}
     */
    private static function summary(BudgetTracker $tracker, array $tracked, ?Budget $budget): array
    {
        return [
            'throughMonth' => $tracked['throughMonth'],
            'actual' => $tracked['result']['actualToDate'],
            'budget' => $tracked['result']['budgetToDate'],
            'endOfYear' => $tracker->cashFlow($tracked['year'], $budget, $tracked['throughMonth'])['endOfYear'],
        ];
    }

    /**
     * Cómo va el año: lo previsto contra lo real por grupo y la caja mes a mes con su
     * proyección a cierre. Es un informe: la pregunta que responde (¿cuadra el año?)
     * se hace de vez en cuando, no cada vez que se entra a anotar.
     */
    #[Route('/report', name: 'accounting_report', methods: ['GET'])]
    public function report(
        Request $request,
        BudgetTracker $tracker,
        BudgetRepository $budgets,
        FinancialAccountRepository $accounts,
        AccountEntryRepository $entries,
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

        $throughMonth = $tracker->lastCompleteMonth($year, $entries->bankBookUpTo());

        return $this->render('accounting/report.html.twig', [
            'year' => $year,
            'years' => $this->yearsWithData($budgets, $entries),
            'budget' => $budget,
            'budgets' => $budgets->findForYear($year),
            'tracked' => $tracker->track($year, $budget, $throughMonth),
            'cash' => $tracker->cashFlow($year, $budget, $throughMonth),
            'bookedMonth' => $tracker->lastMonthWithData($year),
            'balances' => $balances,
            'totalCash' => $totalCash,
            'months' => MonthNames::LONG,
            'shortMonths' => MonthNames::SHORT,
            'kinds' => BudgetCategoryGroup::KIND_LABELS,
        ]);
    }

    /**
     * Índice de los informes: lo que se mira de vez en cuando, no a diario.
     */
    #[Route('/reports', name: 'accounting_reports', methods: ['GET'])]
    public function reports(): Response
    {
        return $this->render('accounting/hub.html.twig', [
            'title' => 'Informes',
            'intro' => 'Lo que se mira de vez en cuando: cómo va el año, el resumen por meses, la hoja de la asamblea, los presupuestos y la entrega a la gestoría.',
            'links' => [
                ['route' => 'accounting_report', 'params' => [], 'icon' => 'fa-line-chart', 'title' => 'Cómo vamos', 'text' => 'Lo presupuestado frente a lo real, y la caja mes a mes hasta fin de año.'],
                ['route' => 'accounting_year', 'params' => ['year' => (int) date('Y')], 'icon' => 'fa-table', 'title' => 'Resumen del año', 'text' => 'Cada partida mes a mes.'],
                ['route' => 'accounting_year_assembly', 'params' => ['year' => (int) date('Y')], 'icon' => 'fa-print', 'title' => 'Para la asamblea', 'text' => 'La hoja del año para imprimir y repartir.'],
                ['route' => 'accounting_budgets', 'params' => [], 'icon' => 'fa-calculator', 'title' => 'Presupuestos', 'text' => 'Los de cada año y cuánto tiene que costar la cesta.'],
                ['route' => 'accounting_accountant_export', 'params' => [], 'icon' => 'fa-briefcase', 'title' => 'Para la gestoría', 'text' => 'El paquete del trimestre: facturas, libros y documentos en un ZIP.'],
            ],
        ]);
    }

    /**
     * Índice de los ajustes: los catálogos que se tocan al empezar y rara vez después.
     * Los sueldos de los trabajadores sólo salen a quien administra la aplicación.
     */
    #[Route('/settings', name: 'accounting_settings', methods: ['GET'])]
    public function settings(): Response
    {
        $links = [
            ['route' => 'accounting_accounts', 'params' => [], 'icon' => 'fa-university', 'title' => 'Cuentas', 'text' => 'Bancos y cajas de efectivo, con su saldo de apertura.'],
            ['route' => 'accounting_categories', 'params' => [], 'icon' => 'fa-tags', 'title' => 'Partidas', 'text' => 'En qué se ingresa y en qué se gasta.'],
            ['route' => 'accounting_providers', 'params' => [], 'icon' => 'fa-truck', 'title' => 'Proveedores', 'text' => 'A quién se le compra.'],
            ['route' => 'accounting_customers', 'params' => [], 'icon' => 'fa-users', 'title' => 'Clientes', 'text' => 'A quién se le factura.'],
        ];
        if ($this->isGranted('ROLE_ADMIN')) {
            $links[] = ['route' => 'accounting_workers', 'params' => [], 'icon' => 'fa-id-card-o', 'title' => 'Trabajadores', 'text' => 'Lo que cuesta cada persona contratada, mes a mes.'];
        }

        return $this->render('accounting/hub.html.twig', [
            'title' => 'Ajustes',
            'intro' => 'Los catálogos del módulo: se preparan al empezar y se tocan poco.',
            'links' => $links,
        ]);
    }

    /**
     * Los movimientos: todos los apuntes, filtrables y paginados. Es la pantalla de
     * quien viene con el extracto delante; con una cuenta elegida lleva, como el
     * extracto, el saldo tras cada movimiento.
     */
    #[Route('/ledger', name: 'accounting_ledger', methods: ['GET'])]
    public function ledger(
        Request $request,
        AccountEntryRepository $entries,
        FinancialAccountRepository $accounts,
        BudgetCategoryRepository $categories,
        PaginatorInterface $paginator,
        ReceivedInvoiceRepository $invoices,
    ): Response {
        $accountId = QueryId::from($request->query, 'account');
        $account = $accountId !== null ? $accounts->find($accountId) : null;

        $filters = [
            'account' => $account,
            'category' => QueryId::from($request->query, 'category'),
            'group' => QueryId::from($request->query, 'group'),
            'from' => $this->parseDate($request->query->get('from')),
            'to' => $this->parseDate($request->query->get('to')),
            'text' => trim((string) $request->query->get('q')) ?: null,
        ];

        $ascending = $request->query->get('direction') === 'asc';
        $qb = $entries->listingQueryBuilder($filters, $ascending);

        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            50,
            ['defaultSortFieldName' => 'e.date', 'defaultSortDirection' => 'desc']
        );

        $pageEntries = array_values(iterator_to_array($pagination->getItems()));
        $pageIds = array_map(static fn (AccountEntry $e): int => (int) $e->getId(), $pageEntries);

        // El saldo fila a fila sólo se puede seguir con todos los movimientos de UNA
        // cuenta: filtrar por partida o por texto deja huecos. Las fechas no molestan.
        $showsRunningBalance = $account !== null && $filters['category'] === null && $filters['group'] === null && $filters['text'] === null;

        return $this->render('accounting/ledger.html.twig', [
            'pagination' => $pagination,
            'invoices' => $invoices->invoiceIdsByEntry($pageIds),
            'running' => $showsRunningBalance ? $entries->runningBalances($account, $pageEntries, $ascending) : null,
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

        // Una cuenta abierta después del mes no tenía saldo entonces: sin el filtro
        // saldría con su saldo de apertura en meses en que aún no existía.
        $balances = array_map(
            static fn (FinancialAccount $account): array => ['account' => $account, 'balance' => $entries->balanceFor($account, $lastDay)],
            array_values(array_filter($accounts->findActive(), static fn (FinancialAccount $account): bool => $account->isOpenOn($lastDay))),
        );

        return $this->render('accounting/month.html.twig', [
            'year' => $year,
            'month' => $month,
            'monthName' => MonthNames::long($month),
            'months' => MonthNames::SHORT,
            'grouped' => $grouped,
            'totals' => $totals,
            // El mismo «Resultado» que cierra el resumen del año: la suma de todo, que
            // es lo que subieron o bajaron los saldos (los traspasos se anulan).
            'result' => array_sum($totals),
            'kinds' => BudgetCategoryGroup::KIND_LABELS,
            'balances' => $balances,
        ]);
    }

    /**
     * El año entero en una rejilla de partida por mes. Es la hoja «Resumen» del
     * Excel, calculada en vez de copiada, con el saldo de cada caja y banco al cerrar
     * cada mes y el de partida que viene del año anterior.
     */
    #[Route('/year/{year}', name: 'accounting_year', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function year(
        int $year,
        BudgetTracker $tracker,
        BudgetRepository $budgets,
        AccountEntryRepository $entries,
    ): Response {
        // Sin presupuesto: la rejilla sólo enseña lo real, y una partida que sólo está
        // presupuestada saldría como una fila entera de «·» con total 0.
        $tracked = $tracker->track($year, null, 12);
        $balances = $entries->balancesByMonthEnd($year);
        // Los meses que aún no han llegado no tienen saldo; el que está en curso
        // enseña el de hoy.
        $balancesThrough = match ($year <=> (int) date('Y')) {
            -1 => 12,
            0 => (int) date('n'),
            1 => 0,
        };

        return $this->render('accounting/year.html.twig', [
            'year' => $year,
            'years' => $this->yearsWithData($budgets, $entries),
            'tracked' => $tracked,
            'months' => MonthNames::SHORT,
            'balances' => $balances,
            'balancesThrough' => $balancesThrough,
            'unexplained' => $this->unexplainedByMonth($balances, $tracked['result']['actual'], $balancesThrough),
        ]);
    }

    /**
     * El cierre del año para la asamblea, para imprimir desde el navegador: lo
     * previsto frente a lo real por grupo, el resultado, y el saldo de cada cuenta al
     * empezar y al acabar el año. La variación de los saldos tiene que ser el
     * resultado; si no lo es, se dice cuánto falta, porque un descuadre en el papel de
     * la asamblea es lo primero que alguien pregunta.
     */
    #[Route('/year/{year}/assembly', name: 'accounting_year_assembly', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function yearAssembly(
        int $year,
        BudgetTracker $tracker,
        BudgetRepository $budgets,
        FinancialAccountRepository $accounts,
        AccountEntryRepository $entries,
    ): Response {
        $budget = $budgets->findApprovedForYear($year);
        $start = new \DateTimeImmutable(sprintf('%d-12-31', $year - 1));
        $end = new \DateTimeImmutable(sprintf('%d-12-31', $year));

        $balances = [];
        foreach ($accounts->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']) as $account) {
            $opened = $account->getOpeningDate();
            if ($opened !== null && $opened > $end) {
                continue; // Abierta después: no existía ese año.
            }
            $balances[] = [
                'account' => $account,
                'openedDuringYear' => $opened !== null && $opened->format('Y-m-d') > sprintf('%d-01-01', $year),
                'start' => (float) $entries->balanceFor($account, $start),
                'end' => (float) $entries->balanceFor($account, $end),
            ];
        }

        // Con el año abierto, lo previsto se mide hasta el último mes con apuntes:
        // contra los doce meses, medio año de gasto parece dinero que sobra.
        $yearClosed = $year < (int) date('Y');
        $tracked = $tracker->track($year, $budget, $yearClosed ? 12 : null);
        $result = $tracked['result']['actualTotal'];
        $variation = array_sum(array_column($balances, 'end')) - array_sum(array_column($balances, 'start'));

        return $this->render('accounting/year_assembly.html.twig', [
            'year' => $year,
            'budget' => $budget,
            'tracked' => $tracked,
            'balances' => $balances,
            'result' => $result,
            'budgetResult' => $tracked['result']['budgetToDate'],
            'throughMonthName' => MonthNames::long($tracked['throughMonth']),
            'variation' => $variation,
            'unexplained' => round($variation - $result, 2),
            'yearClosed' => $yearClosed,
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
        $accountId = QueryId::from($request->query, 'account');
        if ($accountId !== null) {
            $entry->setAccount($accounts->find($accountId));
        }

        $form = $this->createForm(AccountEntryType::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entry->setCreatedBy($this->currentUser());
            $em->persist($entry);
            $em->flush();
            $this->addFlash('success', sprintf('Anotado: %s.', $entry->getConcept()));

            if ($request->request->has('and_new')) {
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
     * Un apunte con todo lo que se sabe de él y, si vino de una factura, el documento
     * al lado: para comprobar un gasto no hace falta ir a buscar el papel.
     */
    #[Route('/entry/{id}', name: 'accounting_entry_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function entryShow(
        AccountEntry $entry,
        ReceivedInvoiceRepository $invoices,
        IssuedInvoiceRepository $issuedInvoices,
        AccountEntryRepository $entries,
        FinancialAccountRepository $accounts,
        BudgetCategoryRepository $categories,
        InvoiceFileStore $files,
        ClosedPeriods $closed,
    ): Response {
        // Un pago agrupado explica varias facturas: el documento es el de la primera.
        $paid = $invoices->findBy(['accountEntry' => $entry], ['id' => 'ASC']);
        $invoice = $paid[0] ?? null;
        $loose = $entry->isLooseTransfer();
        // La pareja va en sentido contrario: si éste entra, la otra sale.
        $nearby = $loose ? array_values(array_filter(
            $entries->findLooseTransfersNear($entry, self::PEER_SEARCH_DAYS),
            static fn (AccountEntry $c): bool => $c->isIncoming() !== $entry->isIncoming(),
        )) : [];

        return $this->render('accounting/entry_show.html.twig', [
            'entry' => $entry,
            'invoice' => $invoice,
            'otherInvoices' => \array_slice($paid, 1),
            'issuedInvoice' => $issuedInvoices->findOneBy(['accountEntry' => $entry]),
            'hasDocument' => $invoice !== null && $files->pathTo($invoice->getFileName()) !== null,
            // En un trimestre entregado no se ofrece cambiarlo: lo pararía el guardado.
            'closedBy' => $closed->closingDeliveryFor($entry->getDate()),
            // Todas las mitades sueltas cercanas, y cuáles casan: las de otro importe
            // se enseñan igual, porque suelen ser la pareja con una errata.
            'nearbyLoose' => $nearby,
            'pairable' => array_map(
                static fn (AccountEntry $c): int => (int) $c->getId(),
                array_filter($nearby, static fn (AccountEntry $c): bool => TransferRecorder::canPair($entry, $c)),
            ),
            'peerAccounts' => $loose ? array_values(array_filter(
                $accounts->findActive(),
                static fn (FinancialAccount $a): bool => $a !== $entry->getAccount(),
            )) : [],
            // Un traspaso no se parte (AccountEntry::splitOff): ni se ofrece.
            'splitCategories' => $entry->isTransfer() ? [] : $categories->findForEntries(),
        ]);
    }

    /**
     * Partir un apunte: separar parte de su importe en otro de la misma cuenta y
     * fecha, con su partida y concepto. Es para los cargos que juntan dos cosas, como
     * el del Ayuntamiento (alquiler con factura + suministros sin ella).
     */
    #[Route('/entry/{id}/split', name: 'accounting_entry_split', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function entrySplit(
        Request $request,
        AccountEntry $entry,
        BudgetCategoryRepository $categories,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
        ClosedPeriods $closed,
    ): Response {
        $back = $this->redirectToRoute('accounting_entry_show', ['id' => $entry->getId()]);
        if (!$this->isCsrfTokenValid('accounting_entry_split_'.$entry->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $back;
        }
        $closed->assertOpen($entry->getDate());

        $part = Amount::parse($request->request->get('amount'));
        $categoryId = $request->request->getInt('category');
        $category = current(array_filter($categories->findForEntries(), static fn (BudgetCategory $c): bool => $c->getId() === $categoryId)) ?: null;
        if ($part === null || $category === null) {
            $this->addFlash('warning', 'Pon el importe que se separa y elige su partida.');

            return $back;
        }

        $whole = $entry->getAmount();
        try {
            $sibling = $entry->splitOff($part, $category, trim((string) $request->request->get('concept')));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $back;
        }
        $sibling->setCreatedBy($this->currentUser());
        $violations = $validator->validate($sibling);
        if (\count($violations) > 0) {
            // El original ya bajó en memoria: se deja como estaba, por si algo guarda después.
            $entry->setAmount($whole);
            $this->addFlash('warning', (string) $violations[0]->getMessage());

            return $back;
        }

        $em->persist($sibling);
        $em->flush();
        $this->addFlash('success', sprintf('Separados %s € a %s. Este apunte queda en %s €.',
            number_format(abs((float) $sibling->getAmount()), 2, ',', '.'),
            $category->getName(),
            number_format(abs((float) $entry->getAmount()), 2, ',', '.'),
        ));

        return $back;
    }

    /**
     * Emparejar una mitad suelta de traspaso con la otra, ya anotada en la otra
     * cuenta. Es lo que pide la portada con los traspasos sin pareja.
     */
    #[Route('/entry/{id}/pair', name: 'accounting_entry_pair', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function entryPair(Request $request, AccountEntry $entry, AccountEntryRepository $entries, TransferRecorder $transfers, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('accounting_entry_pair_'.$entry->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_entry_show', ['id' => $entry->getId()]);
        }

        $peer = $entries->find($request->request->getInt('peer'));
        if ($peer === null || !TransferRecorder::canPair($entry, $peer)) {
            $this->addFlash('warning', 'Esos dos apuntes no son las dos mitades de un mismo traspaso.');

            return $this->redirectToRoute('accounting_entry_show', ['id' => $entry->getId()]);
        }

        $transfers->pair($entry, $peer);
        $em->flush();
        $this->addFlash('success', sprintf('Traspaso emparejado con el apunte de %s.', $peer->getAccount()?->getName()));

        return $this->redirectToRoute('accounting_entry_show', ['id' => $entry->getId()]);
    }

    /**
     * Anotar la mitad que le falta a un traspaso suelto, en la cuenta que se elija:
     * misma fecha y concepto, importe contrario.
     */
    #[Route('/entry/{id}/complete', name: 'accounting_entry_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function entryComplete(
        Request $request,
        AccountEntry $entry,
        FinancialAccountRepository $accounts,
        TransferRecorder $transfers,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): Response {
        $back = $this->redirectToRoute('accounting_entry_show', ['id' => $entry->getId()]);
        if (!$this->isCsrfTokenValid('accounting_entry_complete_'.$entry->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $back;
        }

        $account = $accounts->find($request->request->getInt('account'));
        if ($account === null || !$entry->isLooseTransfer() || $account === $entry->getAccount()) {
            $this->addFlash('warning', 'Elige la otra cuenta del traspaso.');

            return $back;
        }

        $peer = $transfers->complete($entry, $account, $this->currentUser());
        $violations = $validator->validate($peer);
        if (\count($violations) > 0) {
            $entry->pairWith(null);
            $this->addFlash('warning', (string) $violations[0]->getMessage());

            return $back;
        }

        $em->persist($peer);
        $em->flush();
        $this->addFlash('success', sprintf('Anotada la otra mitad en %s. El traspaso ya está completo.', $account->getName()));

        return $back;
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
        ClosedPeriods $closed,
    ): Response {
        // Ni se abre el formulario: lo tecleado se perdería al guardar.
        $closed->assertOpen($entry->getDate());
        if ($entry->isPairedTransfer()) {
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
     * descuadra la cuenta contraria. Si vino de una factura, la factura vuelve a la
     * bandeja: el papel sigue existiendo y está sin anotar.
     */
    #[Route('/entry/{id}/delete', name: 'accounting_entry_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function entryDelete(
        Request $request,
        AccountEntry $entry,
        EntityManagerInterface $em,
        TransferRecorder $transfers,
        ReceivedInvoiceRepository $invoices,
        IssuedInvoiceRepository $issuedInvoices,
    ): Response {
        $accountId = $entry->getAccount()?->getId();

        if (!$this->isCsrfTokenValid('accounting_entry_delete_'.$entry->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_ledger', ['account' => $accountId]);
        }

        if ($entry->isPairedTransfer()) {
            $transfers->remove($entry);
            $done = 'Traspaso borrado, con sus dos mitades.';
        } else {
            // Todas las que explicaba vuelven a la bandeja, también las de un pago agrupado.
            $paid = $invoices->findBy(['accountEntry' => $entry]);
            array_walk($paid, static fn (ReceivedInvoice $i) => $i->reopen());
            // El cobro de una emitida se desliga solo (SET NULL), pero sin avisar la
            // factura pasaría a «sin cobrar» sin que nadie lo supiera.
            $issued = $issuedInvoices->findOneBy(['accountEntry' => $entry]);
            $issued?->unmarkPaid();
            $em->remove($entry);
            $done = match (true) {
                \count($paid) > 1 => sprintf('Apunte borrado. Sus %d facturas vuelven a la bandeja.', \count($paid)),
                $paid !== [] => 'Apunte borrado. Su factura vuelve a la bandeja.',
                $issued !== null => sprintf('Apunte borrado. La factura %s vuelve a estar sin cobrar.', $issued->getNumberLabel()),
                default => 'Apunte borrado.',
            };
        }
        $em->flush();
        // Después del flush: en un trimestre entregado el borrado no llega a hacerse, y
        // el aviso de éxito ya en la sesión saldría junto al que lo prohíbe.
        $this->addFlash('success', $done);

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
     * si no el que está en vigor.
     */
    private function resolveBudget(Request $request, BudgetRepository $budgets, int $year): ?object
    {
        $budgetId = QueryId::from($request->query, 'budget');
        if ($budgetId !== null) {
            $budget = $budgets->find($budgetId);
            if ($budget !== null && $budget->getYear() === $year) {
                return $budget;
            }
        }

        return $budgets->findReferenceForYear($year);
    }

    /**
     * Los meses en que el dinero de las cuentas no cambió lo que dice el resultado:
     * `[mes => diferencia]`, sólo los que descuadran. Lo normal es un traspaso con una
     * sola mitad, o con las dos de importe distinto: mueve un saldo y no cuenta en el
     * resultado. Una cuenta que abre ese mes no descuadra: su saldo de apertura no es
     * movimiento.
     *
     * @param list<array{account: FinancialAccount, balances: array<int, float|null>}> $balances
     * @param array<int, float> $result Resultado de cada mes, de BudgetTracker::track().
     *
     * @return array<int, float>
     */
    private function unexplainedByMonth(array $balances, array $result, int $through): array
    {
        $out = [];
        for ($m = 1; $m <= $through; ++$m) {
            $moved = array_sum(array_map(
                static fn (array $row): float => $row['balances'][$m] === null
                    ? 0.0
                    : $row['balances'][$m] - ($row['balances'][$m - 1] ?? (float) $row['account']->getOpeningBalance()),
                $balances,
            ));
            $diff = round($moved - $result[$m], 2);
            if ($diff != 0.0) {
                $out[$m] = $diff;
            }
        }

        return $out;
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
