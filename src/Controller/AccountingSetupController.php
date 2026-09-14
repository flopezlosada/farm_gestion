<?php

namespace App\Controller;

use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Form\BudgetCategoryType;
use App\Form\FinancialAccountType;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryGroupRepository;
use App\Repository\FinancialAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * El armazón de la contabilidad: dónde está el dinero (las cuentas) y en qué se
 * clasifica (las partidas). Se toca poco y se mira mucho, así que vive aparte del
 * trabajo diario del libro.
 *
 * Nada se borra. Una cuenta o una partida con apuntes detrás no puede desaparecer sin
 * dejar el libro sin explicación, así que se RETIRAN: dejan de ofrecerse al anotar y
 * siguen contando en los años en que se usaron.
 */
#[Route('/gestion/accounting/setup')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class AccountingSetupController extends AbstractController
{
    /**
     * Las cuentas con su saldo de hoy y cuántos apuntes llevan.
     */
    #[Route('/accounts', name: 'accounting_accounts', methods: ['GET'])]
    public function accounts(FinancialAccountRepository $accounts, AccountEntryRepository $entries): Response
    {
        $counts = $entries->countByAccount();

        $rows = [];
        $total = 0.0;
        foreach ($accounts->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']) as $account) {
            $balance = $entries->balanceFor($account);
            $rows[] = [
                'account' => $account,
                'balance' => $balance,
                'entries' => $counts[(int) $account->getId()] ?? 0,
            ];
            if ($account->isActive()) {
                $total += (float) $balance;
            }
        }

        return $this->render('accounting/accounts.html.twig', [
            'rows' => $rows,
            'total' => $total,
        ]);
    }

    /**
     * Abrir una cuenta. El saldo de apertura es el que tiene el día que entra en la
     * aplicación, no el del día que se abrió en el banco.
     */
    #[Route('/accounts/new', name: 'accounting_account_new', methods: ['GET', 'POST'])]
    public function accountNew(Request $request, EntityManagerInterface $em): Response
    {
        $account = new FinancialAccount();
        $account->setOpeningDate(new \DateTimeImmutable('first day of January this year'));

        $form = $this->createForm(FinancialAccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($account);
            $em->flush();
            $this->addFlash('success', sprintf('Cuenta «%s» creada.', $account->getName()));

            return $this->redirectToRoute('accounting_accounts');
        }

        return $this->render('accounting/account_form.html.twig', [
            'form' => $form->createView(),
            'account' => null,
            'entryCount' => 0,
            'heading' => 'Nueva cuenta',
        ]);
    }

    /**
     * Cambiar una cuenta. Si ya tiene apuntes, el saldo de apertura sale bloqueado:
     * tocarlo movería todos los saldos históricos de una vez.
     */
    #[Route('/accounts/{id}/edit', name: 'accounting_account_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function accountEdit(
        Request $request,
        FinancialAccount $account,
        EntityManagerInterface $em,
        AccountEntryRepository $entries,
    ): Response {
        $entryCount = $entries->countByAccount()[(int) $account->getId()] ?? 0;

        $form = $this->createForm(FinancialAccountType::class, $account, ['lock_opening' => $entryCount > 0]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Cuenta actualizada.');

            return $this->redirectToRoute('accounting_accounts');
        }

        return $this->render('accounting/account_form.html.twig', [
            'form' => $form->createView(),
            'account' => $account,
            'entryCount' => $entryCount,
            'heading' => $account->getName(),
        ]);
    }

    /**
     * El catálogo de partidas, agrupado. El grupo es la unidad con la que se compara
     * contra el presupuesto, así que la pantalla enseña esa jerarquía y no una lista
     * plana.
     */
    #[Route('/categories', name: 'accounting_categories', methods: ['GET'])]
    public function categories(BudgetCategoryGroupRepository $groups, AccountEntryRepository $entries): Response
    {
        return $this->render('accounting/categories.html.twig', [
            'groups' => $groups->findAllWithCategories(),
            'counts' => $entries->countByCategory(),
        ]);
    }

    /**
     * Crear una partida.
     */
    #[Route('/categories/new', name: 'accounting_category_new', methods: ['GET', 'POST'])]
    public function categoryNew(Request $request, EntityManagerInterface $em): Response
    {
        $category = new BudgetCategory();
        $form = $this->createForm(BudgetCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
            $em->flush();
            $this->addFlash('success', sprintf('Partida «%s» creada.', $category->getName()));

            return $this->redirectToRoute('accounting_categories');
        }

        return $this->render('accounting/category_form.html.twig', [
            'form' => $form->createView(),
            'category' => null,
            'entryCount' => 0,
            'heading' => 'Nueva partida',
        ]);
    }

    /**
     * Cambiar una partida. Mover de grupo una partida con apuntes cambia dónde suman
     * todos ellos, en todos los años: la pantalla avisa de cuántos son.
     */
    #[Route('/categories/{id}/edit', name: 'accounting_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function categoryEdit(
        Request $request,
        BudgetCategory $category,
        EntityManagerInterface $em,
        AccountEntryRepository $entries,
    ): Response {
        if ($category->isTransfer()) {
            $this->addFlash('warning', 'La partida de traspasos la gestiona la aplicación; no se toca a mano.');

            return $this->redirectToRoute('accounting_categories');
        }

        $form = $this->createForm(BudgetCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Partida actualizada.');

            return $this->redirectToRoute('accounting_categories');
        }

        return $this->render('accounting/category_form.html.twig', [
            'form' => $form->createView(),
            'category' => $category,
            'entryCount' => $entries->countByCategory()[(int) $category->getId()] ?? 0,
            'heading' => $category->getQualifiedName(),
        ]);
    }
}
