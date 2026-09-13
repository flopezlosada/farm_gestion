<?php

namespace App\Controller;

use App\Entity\Budget;
use App\Entity\BudgetCategory;
use App\Entity\BudgetLine;
use App\Form\BudgetType;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryRepository;
use App\Repository\BudgetRepository;
use App\Service\Accounting\MonthNames;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los presupuestos: lo que se espera ingresar y gastar cada mes.
 *
 * Un año puede tener varios —el que aprobó la asamblea y las revisiones de mitad de
 * año— y todos se conservan: el compromiso con la asamblea es el que hay que rendir
 * después, aunque la realidad haya obligado a rehacer las cuentas en julio.
 *
 * Las cifras se teclean en una rejilla de partida por mes, sin formulario de Symfony:
 * son casi quinientas casillas homogéneas y un formulario por casilla sólo añadiría
 * peso. La validación que importa —el signo— la pone el grupo de cada partida.
 */
#[Route('/gestion/accounting/budgets')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class BudgetController extends AbstractController
{
    /**
     * Todos los presupuestos, del más reciente al más viejo, con lo que suman.
     */
    #[Route('/', name: 'accounting_budgets', methods: ['GET'])]
    public function index(BudgetRepository $budgets): Response
    {
        $totals = $budgets->totals();

        $rows = [];
        foreach ($budgets->findBy([], ['year' => 'DESC', 'id' => 'DESC']) as $budget) {
            $total = $totals[(int) $budget->getId()] ?? ['income' => 0.0, 'expense' => 0.0, 'lines' => 0];
            $rows[] = [
                'budget' => $budget,
                'income' => $total['income'],
                'expense' => $total['expense'],
                'result' => $total['income'] + $total['expense'],
                'lines' => $total['lines'],
            ];
        }

        return $this->render('accounting/budgets.html.twig', ['rows' => $rows]);
    }

    /**
     * Crear un presupuesto vacío. Las cifras se meten después, en la rejilla.
     */
    #[Route('/new', name: 'accounting_budget_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $budget = new Budget();
        $budget->setYear((int) date('Y') + 1);
        $budget->setName('Propuesta');

        $form = $this->createForm(BudgetType::class, $budget);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($budget);
            $em->flush();
            $this->addFlash('success', 'Presupuesto creado. Ahora mete las cifras.');

            return $this->redirectToRoute('accounting_budget_lines', ['id' => $budget->getId()]);
        }

        return $this->render('accounting/budget_form.html.twig', [
            'form' => $form->createView(),
            'budget' => null,
            'heading' => 'Nuevo presupuesto',
        ]);
    }

    /**
     * Cambiar los datos de cabecera: nombre, año, tesorería de partida, si está
     * aprobado y por qué se hizo así.
     */
    #[Route('/{id}/edit', name: 'accounting_budget_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Budget $budget, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BudgetType::class, $budget);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Presupuesto actualizado.');

            return $this->redirectToRoute('accounting_budgets');
        }

        return $this->render('accounting/budget_form.html.twig', [
            'form' => $form->createView(),
            'budget' => $budget,
            'heading' => $budget->getYear().' · '.$budget->getName(),
        ]);
    }

    /**
     * La rejilla: cada partida y sus doce meses.
     *
     * Se teclea en POSITIVO. El signo lo pone el grupo —lo de gastos e inversión sale,
     * lo de ingresos entra— y así comparar contra el libro es restar. La excepción es
     * FINANCIACIÓN, donde un préstamo entra y sus cuotas salen: ahí el signo se escribe.
     *
     * Como referencia para no presupuestar a ciegas, cada fila lleva al lado lo que esa
     * partida movió de verdad el año anterior.
     */
    #[Route('/{id}/lines', name: 'accounting_budget_lines', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function lines(
        Budget $budget,
        BudgetCategoryRepository $categories,
        AccountEntryRepository $entries,
    ): Response {
        $grid = [];
        foreach ($budget->getLines() as $line) {
            $grid[(int) $line->getCategory()?->getId()][$line->getMonth()] = (float) $line->getAmount();
        }

        $lastYearReal = $entries->totalsByCategoryAndMonth((int) $budget->getYear() - 1);
        $lastYearTotals = [];
        foreach ($lastYearReal as $categoryId => $months) {
            $lastYearTotals[$categoryId] = array_sum(array_map('floatval', $months));
        }

        return $this->render('accounting/budget_lines.html.twig', [
            'budget' => $budget,
            'groups' => $this->gridGroups($budget, $categories),
            'grid' => $grid,
            'lastYearTotals' => $lastYearTotals,
            'months' => MonthNames::SHORT,
        ]);
    }

    /**
     * Guardar la rejilla entera. Una casilla vacía o a cero no genera línea: el
     * presupuesto guarda lo que se prevé, no cuatrocientos ceros.
     */
    #[Route('/{id}/lines', name: 'accounting_budget_lines_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveLines(
        Request $request,
        Budget $budget,
        EntityManagerInterface $em,
        BudgetCategoryRepository $categories,
    ): Response {
        if (!$this->isCsrfTokenValid('accounting_budget_lines_'.$budget->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_budget_lines', ['id' => $budget->getId()]);
        }

        $existing = [];
        foreach ($budget->getLines() as $line) {
            $existing[(int) $line->getCategory()?->getId()][$line->getMonth()] = $line;
        }

        $posted = $request->request->all('line');
        // Un envío sin casillas no es «bórralo todo», es un envío roto. Sin esta
        // guarda, cualquier POST incompleto se llevaría por delante el presupuesto
        // entero sin que nadie lo hubiera pedido.
        if ($posted === []) {
            $this->addFlash('warning', 'No ha llegado ninguna cifra, así que no se ha tocado nada.');

            return $this->redirectToRoute('accounting_budget_lines', ['id' => $budget->getId()]);
        }

        $changed = 0;
        $expected = 0;
        $received = 0;

        foreach ($this->gridGroups($budget, $categories) as $group) {
            $sign = $group['group']->expectedSign();
            foreach ($group['categories'] as $category) {
                $categoryId = (int) $category->getId();
                for ($month = 1; $month <= 12; ++$month) {
                    ++$expected;
                    $raw = $posted[$categoryId][$month] ?? null;
                    if ($raw !== null) {
                        ++$received;
                    }
                    // Casilla que no viene en el envío: se deja como está. Vacía sí
                    // significa cero —alguien la ha borrado a propósito—, pero ausente
                    // sólo significa que no se envió.
                    if ($raw === null) {
                        continue;
                    }

                    $amount = $this->parseAmount($raw);
                    if ($sign !== 0) {
                        $amount = $sign * abs($amount);
                    }
                    $line = $existing[$categoryId][$month] ?? null;

                    if (abs($amount) < 0.005) {
                        if ($line !== null) {
                            $budget->removeLine($line);
                            $em->remove($line);
                            ++$changed;
                        }
                        continue;
                    }

                    $money = number_format($amount, 2, '.', '');
                    if ($line === null) {
                        $line = new BudgetLine($budget, $category, $month, $money);
                        $budget->addLine($line);
                        $em->persist($line);
                        ++$changed;
                    } elseif ($line->getAmount() !== $money) {
                        $line->setAmount($money);
                        ++$changed;
                    }
                }
            }
        }

        $em->flush();
        $this->addFlash('success', $changed === 0 ? 'No había nada que cambiar.' : sprintf('Presupuesto guardado (%d casillas tocadas).', $changed));

        // La rejilla envía una variable por casilla, y PHP descarta las que pasen de
        // `max_input_vars` sin decir nada: el envío llegaría recortado y sólo se
        // guardaría media pantalla. Las que faltan quedan como estaban (arriba), pero
        // hay que enterarse.
        if ($received < $expected) {
            $this->addFlash('warning', sprintf(
                'Ojo: sólo llegaron %d de las %d casillas. El servidor ha recortado el envío (max_input_vars); lo que no llegó se ha quedado como estaba.',
                $received,
                $expected
            ));
        }

        return $this->redirectToRoute('accounting_budget_lines', ['id' => $budget->getId()]);
    }

    /**
     * Copiar un presupuesto entero, cifras incluidas. Es como se empieza el del año
     * siguiente y como se arranca una revisión sin perder el aprobado.
     */
    #[Route('/{id}/duplicate', name: 'accounting_budget_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(Request $request, Budget $budget, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('accounting_budget_duplicate_'.$budget->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_budgets');
        }

        $year = (int) $request->request->get('year', (string) $budget->getYear());

        $copy = (new Budget())
            ->setYear($year)
            ->setName($this->freeName($budget, $year, $em))
            ->setOpeningCash($budget->getOpeningCash())
            ->setNotes($budget->getNotes());
        // La copia NUNCA nace aprobada: aprobar es un acto de la asamblea, no un efecto
        // colateral de copiar.
        $copy->setApproved(false);

        foreach ($budget->getLines() as $line) {
            $copy->addLine(new BudgetLine($copy, $line->getCategory(), $line->getMonth(), $line->getAmount()));
        }

        $em->persist($copy);
        $em->flush();
        $this->addFlash('success', sprintf('Copiado en «%s» de %d.', $copy->getName(), $copy->getYear()));

        return $this->redirectToRoute('accounting_budget_lines', ['id' => $copy->getId()]);
    }

    /**
     * Borrar un presupuesto con sus cifras. Sólo tiene sentido con los escenarios de
     * trabajo; el aprobado es el compromiso con la asamblea y no se borra.
     */
    #[Route('/{id}/delete', name: 'accounting_budget_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Budget $budget, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('accounting_budget_delete_'.$budget->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_budgets');
        }

        if ($budget->isApproved()) {
            $this->addFlash('warning', 'Un presupuesto aprobado no se borra. Desmárcalo primero si de verdad quieres quitarlo.');

            return $this->redirectToRoute('accounting_budgets');
        }

        $em->remove($budget);
        $em->flush();
        $this->addFlash('success', 'Presupuesto borrado.');

        return $this->redirectToRoute('accounting_budgets');
    }

    /**
     * Las filas de la rejilla: las partidas en uso, más las retiradas que este
     * presupuesto ya usa. Si una retirada no se pintara, guardar borraría su cifra sin
     * que nadie lo hubiera pedido.
     *
     * @return array<int, array{group: \App\Entity\BudgetCategoryGroup, categories: BudgetCategory[]}>
     */
    private function gridGroups(Budget $budget, BudgetCategoryRepository $categories): array
    {
        $wanted = [];
        foreach ($categories->findActive() as $category) {
            $wanted[(int) $category->getId()] = $category;
        }
        foreach ($budget->getLines() as $line) {
            $category = $line->getCategory();
            if ($category !== null) {
                $wanted[(int) $category->getId()] = $category;
            }
        }

        $groups = [];
        foreach ($wanted as $category) {
            $group = $category->getGroup();
            // Los traspasos no se presupuestan: mover dinero de una cuenta a otra no
            // cambia lo que la asociación ingresa ni gasta.
            if ($group === null || $group->isTransfer()) {
                continue;
            }
            $key = (int) $group->getId();
            $groups[$key]['group'] ??= $group;
            $groups[$key]['categories'][] = $category;
        }

        uasort($groups, static fn (array $a, array $b) => $a['group']->getSortOrder() <=> $b['group']->getSortOrder());
        foreach ($groups as $key => $group) {
            usort($groups[$key]['categories'], static fn (BudgetCategory $a, BudgetCategory $b) => [$a->getSortOrder(), $a->getName()] <=> [$b->getSortOrder(), $b->getName()]);
        }

        return $groups;
    }

    /**
     * Un nombre libre para la copia, porque año y nombre no se pueden repetir.
     */
    private function freeName(Budget $source, int $year, EntityManagerInterface $em): string
    {
        $repository = $em->getRepository(Budget::class);
        $base = $source->getName().' (copia)';
        $name = $base;
        $attempt = 2;
        while ($repository->findOneBy(['year' => $year, 'name' => $name]) !== null) {
            $name = $base.' '.$attempt;
            ++$attempt;
        }

        return $name;
    }

    /**
     * Lee una casilla de la rejilla. Se admite la coma decimal porque es lo que sale
     * del teclado numérico y lo que la gente escribe; los puntos de millar se ignoran.
     */
    private function parseAmount(mixed $raw): float
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return 0.0;
        }
        $value = str_replace(['.', ' ', '€'], '', $value);
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
