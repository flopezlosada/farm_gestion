<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Partir un apunte desde su ficha: el cargo del Ayuntamiento (158,95 €) se cuenta en
 * alquiler (108,95) y suministros (50). La regla de cuánto cabe vive en la entidad
 * ({@see \App\Tests\Entity\AccountEntrySplitTest}); aquí, el formulario y lo que un
 * POST trucado no debe conseguir.
 *
 * Autocontenido: cuenta y partidas propias, en 2090.
 */
class AccountEntrySplitTest extends AbstractAuthenticatedTest
{
    /** @var list<array{class-string, int}> Lo creado, para borrarlo al acabar (apuntes primero). */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $accounts = array_map(
            static fn (array $row): int => $row[1],
            array_filter($this->created, static fn (array $row): bool => $row[0] === FinancialAccount::class),
        );
        foreach ($em->getRepository(AccountEntry::class)->findBy(['account' => $accounts]) as $entry) {
            $entry->pairWith(null);
            $em->remove($entry);
        }
        $em->flush();
        foreach (array_reverse($this->created) as [$class, $id]) {
            if (($row = $em->find($class, $id)) !== null) {
                $em->remove($row);
            }
        }
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testLaFichaParteElCargoDelAyuntamiento(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$bank, $rent, $supplies] = $this->catalog();
        $entry = $this->entry($bank, $rent, '-158.95');
        $this->em()->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $entry->getId()));
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/split"]');
        $this->assertCount(1, $form);
        $this->assertCount(0, $form->filter(sprintf('option[value="%d"]', $this->transferCategory()->getId())), 'Los traspasos no se ofrecen.');

        $client->submit($form->form([
            'amount' => '50,00',
            'category' => (string) $supplies->getId(),
            'concept' => 'Suministros del local',
        ]));
        $this->assertResponseRedirects(sprintf('/gestion/accounting/entry/%d', $entry->getId()));
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Separados 50,00 €');

        $this->em()->clear();
        $this->assertSame('-108.95', $this->em()->find(AccountEntry::class, $entry->getId())->getAmount());
        $sibling = $this->em()->getRepository(AccountEntry::class)->findOneBy(['account' => $bank, 'category' => $supplies]);
        $this->assertNotNull($sibling);
        $this->assertSame('-50.00', $sibling->getAmount());
        $this->assertSame('2090-03-01', $sibling->getDate()->format('Y-m-d'));
        $this->assertSame('Suministros del local', $sibling->getConcept());
        $this->assertNotNull($sibling->getCreatedBy(), 'Queda quién lo partió.');
    }

    /** Lo que no vale no toca la base: ni el original baja ni aparece el hermano. */
    public function testUnPostQueNoValeNoParteNada(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$bank, $rent, $supplies] = $this->catalog();
        $entry = $this->entry($bank, $rent, '-158.95');
        $this->em()->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $entry->getId()));
        $token = $crawler->filter('form[action$="/split"] input[name="_token"]')->attr('value');
        $url = sprintf('/gestion/accounting/entry/%d/split', $entry->getId());
        foreach ([
            'todo el importe' => ['amount' => '158,95', 'category' => $supplies->getId(), 'concept' => 'Suministros'],
            'sin importe' => ['amount' => '', 'category' => $supplies->getId(), 'concept' => 'Suministros'],
            'sin concepto' => ['amount' => '50', 'category' => $supplies->getId(), 'concept' => '   '],
            'a un traspaso' => ['amount' => '50', 'category' => $this->transferCategory()->getId(), 'concept' => 'A la caja'],
            'sin token' => ['amount' => '50', 'category' => $supplies->getId(), 'concept' => 'Suministros', '_token' => 'x'],
        ] as $case => $post) {
            $client->request('POST', $url, $post + ['_token' => $token]);
            $this->assertResponseRedirects(null, null, $case);
            $this->em()->clear();
            $this->assertSame('-158.95', $this->em()->find(AccountEntry::class, $entry->getId())->getAmount(), $case);
            $this->assertCount(1, $this->em()->getRepository(AccountEntry::class)->findBy(['account' => $bank]), $case);
        }
    }

    /** Un traspaso no ofrece partirse (la regla, en la entidad: AccountEntry::splitOff). */
    public function testUnTraspasoNoOfrecePartirse(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$bank] = $this->catalog();
        $entry = $this->entry($bank, $this->transferCategory(), '-100.00');
        $this->em()->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $entry->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('form[action$="/split"]'));
    }

    /**
     * @return array{0: FinancialAccount, 1: BudgetCategory, 2: BudgetCategory}
     */
    private function catalog(): array
    {
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        $group = (new BudgetCategoryGroup())->setName('GASTOS PRUEBA '.$suffix)->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $rent = (new BudgetCategory())->setGroup($group)->setName('Local '.$suffix)->setSortOrder(1);
        $supplies = (new BudgetCategory())->setGroup($group)->setName('Suministros '.$suffix)->setSortOrder(2);
        $bank = (new FinancialAccount())->setName('Banco prueba '.$suffix)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2090-01-01'))->setActive(true)->setSortOrder(99);
        array_map([$em, 'persist'], [$group, $rent, $supplies, $bank]);
        $em->flush();
        array_push($this->created, [BudgetCategoryGroup::class, $group->getId()], [BudgetCategory::class, $rent->getId()],
            [BudgetCategory::class, $supplies->getId()], [FinancialAccount::class, $bank->getId()]);

        return [$bank, $rent, $supplies];
    }

    /** Una partida de traspasos propia: la base de tests no tiene por qué traerla sembrada. */
    private function transferCategory(): BudgetCategory
    {
        foreach ($this->created as [$class, $id]) {
            $row = $class === BudgetCategory::class ? $this->em()->find($class, $id) : null;
            if ($row !== null && $row->isTransfer()) {
                return $row;
            }
        }
        $em = $this->em();
        $group = (new BudgetCategoryGroup())->setName('TRASPASOS PRUEBA '.bin2hex(random_bytes(3)))->setKind(BudgetCategoryGroup::KIND_TRANSFER)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Traspaso entre cuentas')->setSortOrder(1);
        array_map([$em, 'persist'], [$group, $category]);
        $em->flush();
        array_push($this->created, [BudgetCategoryGroup::class, $group->getId()], [BudgetCategory::class, $category->getId()]);

        return $category;
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $amount): AccountEntry
    {
        $entry = (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable('2090-03-01'))->setConcept('RECIBO AYUNTAMIENTO')->setAmount($amount);
        $this->em()->persist($entry);

        return $entry;
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
