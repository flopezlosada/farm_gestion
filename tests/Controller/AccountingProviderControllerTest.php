<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\Provider;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El proveedor como ficha en los gastos: se da de alta a mano, se elige al anotar
 * (y el nombre del apunte pasa a ser el de la ficha) y su ficha enseña también lo que
 * se le paga sin factura.
 *
 * Autocontenido: cuenta, partida y proveedor propios, en 2089.
 */
class AccountingProviderControllerTest extends AbstractAuthenticatedTest
{
    private const NAME = 'ZZTEST Ayuntamiento de prueba';

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $accounts = array_column(array_filter($this->created, static fn (array $r): bool => $r[0] === FinancialAccount::class), 1);
        foreach ($em->getRepository(AccountEntry::class)->findBy(['account' => $accounts]) as $entry) {
            $em->remove($entry);
        }
        $em->flush();
        foreach ($em->getRepository(Provider::class)->findBy(['name' => self::NAME]) as $provider) {
            $em->remove($provider);
        }
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

    public function testAltaAnotarConLaFichaYVerloEnElla(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        $crawler = $client->request('GET', '/gestion/accounting/providers/new');
        $this->assertResponseIsSuccessful();
        $client->submit($crawler->filter('form')->reduce(static fn ($f): bool => $f->filter('[name$="[name]"]')->count() > 0)->form(), [
            'accounting_provider[name]' => self::NAME,
        ]);
        $this->assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = $em->getRepository(Provider::class)->findOneBy(['name' => self::NAME]);
        $this->assertNotNull($provider);

        [$bank, $category] = $this->catalog();
        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/new?account=%d&date=2089-03-02', $bank->getId()));
        $this->assertCount(1, $crawler->filter(sprintf('#account_entry_provider option[value="%d"]', $provider->getId())));
        $form = $crawler->filter('form[name="account_entry"]')->form();
        $form['account_entry[concept]'] = 'Suministros del local';
        $form['account_entry[category]']->select((string) $category->getId());
        $form['account_entry[direction]']->select('out');
        $form['account_entry[magnitude]'] = '50.00';
        $form['account_entry[providerName]'] = 'texto viejo';
        $form['account_entry[provider]']->select((string) $provider->getId());
        $client->submit($form);
        $this->assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $entry = $em->getRepository(AccountEntry::class)->findOneBy(['account' => $bank->getId()]);
        $this->assertSame($provider->getId(), $entry->getProvider()?->getId());
        $this->assertSame(self::NAME, $entry->getProviderName(), 'Manda el nombre de la ficha, no el texto.');

        $client->request('GET', sprintf('/gestion/accounting/providers/%d', $provider->getId()));
        $this->assertSelectorTextContains('body', 'Pagos sin factura');
        $this->assertSelectorTextContains('body', 'Suministros del local');
    }

    /** @return array{0: FinancialAccount, 1: BudgetCategory} */
    private function catalog(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));
        $group = (new BudgetCategoryGroup())->setName('GASTOS PROVEEDOR '.$suffix)->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Local '.$suffix)->setSortOrder(1);
        $bank = (new FinancialAccount())->setName('Banco proveedor '.$suffix)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2089-01-01'))->setActive(true)->setSortOrder(99);
        array_map([$em, 'persist'], [$group, $category, $bank]);
        $em->flush();
        array_push($this->created, [BudgetCategoryGroup::class, $group->getId()], [BudgetCategory::class, $category->getId()], [FinancialAccount::class, $bank->getId()]);

        return [$bank, $category];
    }
}
