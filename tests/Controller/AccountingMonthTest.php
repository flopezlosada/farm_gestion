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
 * La hoja de un mes: cierra con el resultado, como el resumen del año, y los saldos
 * son sólo los de las cuentas que ya existían ese mes.
 *
 * Autocontenido: cuenta, grupos y partidas propias, en 2091.
 */
class AccountingMonthTest extends AbstractAuthenticatedTest
{
    /** @var list<object> Lo creado, en orden de creación, para borrarlo al revés. */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        foreach (array_reverse($this->created) as $entity) {
            if (($row = $em->find($entity::class, $entity->getId())) !== null) {
                $em->remove($row);
            }
        }
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testElMesCierraConElResultadoYElSaldoDeLaCuenta(): void
    {
        $client = $this->createAuthenticatedClient();
        $account = $this->catalog();

        $crawler = $client->request('GET', '/gestion/accounting/month/2091/3');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-topbar__crumbs', 'Resumen 2091');
        $this->assertSelectorTextContains('.acc-result-row', 'Resultado del mes');
        $this->assertSelectorTextContains('.acc-result-row', '70,00 €');
        $this->assertStringContainsString('570 €', $crawler->filter('.prl-stat')->reduce(
            static fn ($stat): bool => str_contains($stat->text(), $account->getName()),
        )->text());
    }

    /** El mes anterior a la apertura la cuenta no existía: su saldo de apertura sería falso. */
    public function testUnaCuentaAunSinAbrirNoSaleEnLosSaldos(): void
    {
        $client = $this->createAuthenticatedClient();
        $account = $this->catalog();

        $client->request('GET', '/gestion/accounting/month/2091/2');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString($account->getName(), $client->getCrawler()->filter('body')->text());
    }

    /**
     * Una cuenta abierta el 1 de marzo de 2091 con 500 €, un cobro de 100 € y un
     * pago de 30 € en ese mes, cada uno en su grupo.
     */
    private function catalog(): FinancialAccount
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $suffix = bin2hex(random_bytes(3));
        $incomeGroup = (new BudgetCategoryGroup())->setName('INGRESOS PRUEBA '.$suffix)->setKind(BudgetCategoryGroup::KIND_INCOME)->setSortOrder(97);
        $income = (new BudgetCategory())->setGroup($incomeGroup)->setName('Venta de prueba '.$suffix)->setSortOrder(1);
        $expenseGroup = (new BudgetCategoryGroup())->setName('GASTOS PRUEBA '.$suffix)->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(98);
        $expense = (new BudgetCategory())->setGroup($expenseGroup)->setName('Compra de prueba '.$suffix)->setSortOrder(1);
        $account = (new FinancialAccount())->setName('Banco del mes '.$suffix)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('500.00')->setOpeningDate(new \DateTimeImmutable('2091-03-01'))->setActive(true)->setSortOrder(99);
        $entries = [
            $this->entry($account, $income, '2091-03-10', '100.00'),
            $this->entry($account, $expense, '2091-03-20', '-30.00'),
        ];

        $em = $this->em();
        $this->created = [$incomeGroup, $income, $expenseGroup, $expense, $account, ...$entries];
        array_map([$em, 'persist'], $this->created);
        $em->flush();

        return $account;
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount): AccountEntry
    {
        return (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept('Apunte de prueba')->setAmount($amount);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
