<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\Budget;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\BudgetLine;
use App\Entity\FinancialAccount;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Las fichas de cuenta y de partida. Trabajan sobre 1999, un año que nadie más usa en
 * db_test: el corte de «hasta qué mes hay apuntes» es del libro entero y cualquier
 * apunte ajeno del mismo año lo movería.
 */
class AccountingSetupControllerTest extends AbstractAuthenticatedTest
{
    private const YEAR = 1999;

    private KernelBrowser $browser;
    private EntityManagerInterface $em;
    private FinancialAccount $account;
    private BudgetCategory $category;
    /** @var list<object> Lo creado por el test, en el orden en que se creó. */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->account = (new FinancialAccount())->setName('Banco fichas '.bin2hex(random_bytes(3)))
            ->setKind(FinancialAccount::KIND_BANK)->setOpeningBalance('1000.00')
            ->setOpeningDate(new \DateTimeImmutable(self::YEAR.'-01-01'))->setActive(true)->setSortOrder(99);
        $group = (new BudgetCategoryGroup())->setName('GASTOS FICHAS '.bin2hex(random_bytes(3)))
            ->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $this->category = (new BudgetCategory())->setGroup($group)->setName('Semillas')->setSortOrder(1);
        $this->persist($this->account, $group, $this->category);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (array_reverse($this->created) as $row) {
            $found = $this->em->find($row::class, $row->getId());
            if ($found !== null) {
                $this->em->remove($found);
            }
        }
        foreach ($this->em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $this->em->remove($setting);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /**
     * Con el libro anotado hasta marzo, de abril en adelante no hay real todavía: ni
     * cero ni desviación, y lo previsto con lo que se compara es el de esos tres meses.
     */
    public function testLaFichaDeUnaPartidaNoCuentaComoDesviacionLosMesesSinAnotar(): void
    {
        $this->persist($this->entry(self::YEAR.'-01-15', '-100.00'), $this->entry(self::YEAR.'-03-20', '-50.00'));
        $budget = (new Budget())->setYear(self::YEAR)->setName('Presupuesto fichas')->setApproved(true);
        foreach (range(1, 12) as $month) {
            $budget->addLine((new BudgetLine())->setCategory($this->category)->setMonth($month)->setAmount('-80.00'));
        }
        $this->persist($budget);

        $crawler = $this->browser->request('GET', '/gestion/accounting/setup/categories/'.$this->category->getId().'?year='.self::YEAR);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.prl-stats', 'previsto hasta marzo');
        $this->assertSelectorTextContains('.prl-stats', '-240,00 €');
        $this->assertSelectorTextContains('.prl-stats', '-960,00 €');
        $this->assertSelectorTextContains('.prl-stats', 'previsto en todo '.self::YEAR);

        $months = $crawler->filter('table.prl-table')->first()->filter('tbody tr');
        $this->assertCount(12, $months);
        $this->assertSame('-20,00 €', trim($months->eq(0)->filter('td')->eq(3)->text()), 'enero: gastó 20 € más de lo previsto');
        $this->assertSame('+80,00 €', trim($months->eq(1)->filter('td')->eq(3)->text()), 'febrero anotado sin gasto: cero de verdad');
        $this->assertSame('—', trim($months->eq(3)->filter('td')->eq(2)->text()), 'abril aún no tiene real');
        $this->assertSame('—', trim($months->eq(3)->filter('td')->eq(3)->text()), 'ni diferencia');
        $this->assertSame(
            sprintf('/gestion/accounting/ledger?category=%d&from=%d-02-01&to=%d-02-%s', $this->category->getId(), self::YEAR, self::YEAR, date('t', mktime(0, 0, 0, 2, 1, self::YEAR))),
            $months->eq(1)->filter('td a')->attr('href'),
            'el mes abre los movimientos de esta partida en ese mes, no el mes de todas'
        );

        $recentHeaders = $crawler->filter('table.prl-table')->last()->filter('thead th')->each(fn ($th) => trim($th->text()));
        $this->assertNotContains('Partida', $recentHeaders, 'en la ficha de la partida, la partida sería la misma en todas las filas');
        $this->assertContains('Cuenta', $recentHeaders);
    }

    /** Cada mes con su nombre y el saldo en euros: una caja pequeña no cabe en «0,4k». */
    public function testLaFichaDeUnaCuentaPintaElSaldoDeCadaMesConSuNombre(): void
    {
        $this->persist($this->entry(self::YEAR.'-01-10', '-100.00'), $this->entry(self::YEAR.'-02-10', '-499.60'));

        $crawler = $this->browser->request('GET', '/gestion/accounting/setup/accounts/'.$this->account->getId().'?year='.self::YEAR);

        $this->assertResponseIsSuccessful();
        $months = $crawler->filter('.acc-months .m');
        $this->assertCount(12, $months, 'un año pasado se ve entero');
        $this->assertStringContainsString('Ene', $months->eq(0)->text());
        $this->assertSame('900 €', trim($months->eq(0)->filter('.v')->text()));
        $this->assertSame('400 €', trim($months->eq(1)->filter('.v')->text()));
        $this->assertStringContainsString('Dic', $months->eq(11)->text());

        $recentHeaders = $crawler->filter('table.prl-table thead th')->each(fn ($th) => trim($th->text()));
        $this->assertNotContains('Cuenta', $recentHeaders);
        $this->assertContains('Partida', $recentHeaders);
    }

    /**
     * Arrastrar en el catálogo manda el grupo entero en su orden nuevo, y cada partida
     * se queda con su puesto de 10 en 10.
     */
    public function testElCatalogoOrdenaLasPartidasDeUnGrupo(): void
    {
        $second = (new BudgetCategory())->setGroup($this->category->getGroup())->setName('Gasolina')->setSortOrder(2);
        $this->persist($second);

        $this->browser->request('POST', $this->orderUrl(), [
            '_token' => $this->orderToken(),
            'ids' => [$second->getId(), $this->category->getId()],
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->em->clear();
        $this->assertSame(10, $this->em->find(BudgetCategory::class, $second->getId())->getSortOrder());
        $this->assertSame(20, $this->em->find(BudgetCategory::class, $this->category->getId())->getSortOrder());
    }

    /**
     * Una lista que no es exactamente la del grupo (una pestaña vieja, sin la partida
     * que se creó después) no se aplica: dejaría la nueva sin puesto.
     */
    public function testElCatalogoRechazaUnOrdenQueNoTraeTodoElGrupo(): void
    {
        $second = (new BudgetCategory())->setGroup($this->category->getGroup())->setName('Gasolina')->setSortOrder(2);
        $this->persist($second);

        $this->browser->request('POST', $this->orderUrl(), [
            '_token' => $this->orderToken(),
            'ids' => [$second->getId()],
        ]);

        $this->assertResponseStatusCodeSame(409);
        $this->em->clear();
        $this->assertSame(1, $this->em->find(BudgetCategory::class, $this->category->getId())->getSortOrder());
        $this->assertSame(2, $this->em->find(BudgetCategory::class, $second->getId())->getSortOrder());
    }

    /** El orden ya no se teclea: la partida nueva va la última de su grupo. */
    public function testUnaPartidaNuevaVaLaUltimaDeSuGrupo(): void
    {
        $crawler = $this->browser->request('GET', '/gestion/accounting/setup/categories/new');
        $form = $crawler->selectButton('Guardar')->form();
        $this->assertFalse($form->has($form->getName().'[sortOrder]'), 'el orden se decide arrastrando en el catálogo');
        $form[$form->getName().'[group]'] = (string) $this->category->getGroup()->getId();
        $form[$form->getName().'[name]'] = 'Abono '.bin2hex(random_bytes(3));
        $this->browser->submit($form);

        $this->assertResponseRedirects('/gestion/accounting/setup/categories');
        $this->em->clear();
        $created = $this->em->getRepository(BudgetCategory::class)->findOneBy(['name' => $form[$form->getName().'[name]']->getValue()]);
        $this->created[] = $created;
        $this->assertSame(11, $created->getSortOrder(), 'detrás de la que había, con orden 1');
    }

    private function orderUrl(): string
    {
        return '/gestion/accounting/setup/groups/'.$this->category->getGroup()->getId().'/order';
    }

    /** El token CSRF tal como lo pinta el catálogo para el grupo. */
    private function orderToken(): string
    {
        $crawler = $this->browser->request('GET', '/gestion/accounting/setup/categories');
        $tbody = $crawler->filter(sprintf('tbody[data-sort-url="%s"]', $this->orderUrl()));
        $this->assertCount(1, $tbody, 'el grupo se puede ordenar arrastrando');

        return (string) $tbody->attr('data-sort-token');
    }

    private function entry(string $date, string $amount): AccountEntry
    {
        return (new AccountEntry())->setAccount($this->account)->setCategory($this->category)
            ->setDate(new \DateTimeImmutable($date))->setConcept('Prueba de ficha')->setAmount($amount);
    }

    private function persist(object ...$rows): void
    {
        foreach ($rows as $row) {
            $this->em->persist($row);
            $this->created[] = $row;
        }
        $this->em->flush();
    }
}
