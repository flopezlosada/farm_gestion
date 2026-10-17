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
 * La ficha de un traspaso al que le falta la otra mitad, que es adonde manda la
 * portada. Antes era un callejón sin salida: no se podía corregir ni emparejar, sólo
 * borrar. Ahora enseña las mitades sueltas de la otra cuenta, empareja la que casa,
 * anota la que falta, y deja corregir la mitad suelta (no la emparejada).
 *
 * Autocontenido: cuentas y partida propias, en 2090.
 */
class AccountEntryLooseTransferTest extends AbstractAuthenticatedTest
{
    /** @var list<array{class-string, int}> Lo creado, para borrarlo al acabar (apuntes primero). */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        foreach ($this->em()->getRepository(AccountEntry::class)->findBy(['account' => array_map(
            static fn (array $row): int => $row[1],
            array_filter($this->created, static fn (array $row): bool => $row[0] === FinancialAccount::class),
        )]) as $entry) {
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

    public function testLaFichaEmparejaLaMitadQueCasaYEnsenaLaDeOtroImporte(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$category, $box, $bank] = $this->catalog();
        $in = $this->entry($bank, $category, '2090-02-05', '100.00');
        $match = $this->entry($box, $category, '2090-02-04', '-100.00');
        $typo = $this->entry($box, $category, '2090-02-04', '-115.00');
        $this->em()->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $in->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Le falta la otra mitad');
        $pairForms = $crawler->filter('form[action$="/pair"]');
        $this->assertCount(1, $pairForms, 'Sólo se empareja la que casa.');
        $this->assertSame((string) $match->getId(), $pairForms->form()['peer']->getValue());
        $this->assertSelectorTextContains('body', 'otro importe');
        $this->assertCount(1, $crawler->filter(sprintf('a[href="/gestion/accounting/entry/%d"]', $typo->getId())), 'La errata se ve, para corregirla.');

        $client->submit($pairForms->form());
        $this->assertResponseRedirects();
        $this->em()->clear();
        $this->assertSame($match->getId(), $this->em()->find(AccountEntry::class, $in->getId())->getTransferPeer()?->getId());
        $this->assertSame($in->getId(), $this->em()->find(AccountEntry::class, $match->getId())->getTransferPeer()?->getId());

        $client->request('GET', sprintf('/gestion/accounting/entry/%d/edit', $in->getId()));
        $this->assertResponseRedirects(null, null, 'Emparejado ya no se corrige por mitades.');
    }

    /** Una errata de importe se corrige en la mitad suelta, y la emparejada no se puede emparejar otra vez. */
    public function testLaMitadSueltaSeCorrigeYUnPostTrucadoNoEmpareja(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$category, $box, $bank] = $this->catalog();
        $in = $this->entry($bank, $category, '2090-02-05', '115.00');
        $out = $this->entry($box, $category, '2090-02-05', '-100.00');
        $this->em()->flush();

        $client->request('GET', sprintf('/gestion/accounting/entry/%d/edit', $in->getId()));
        $this->assertResponseIsSuccessful();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $in->getId()));
        $this->assertCount(0, $crawler->filter('form[action$="/pair"]'));
        $token = $crawler->filter('form[action$="/complete"] input[name="_token"]')->attr('value');
        $client->request('POST', sprintf('/gestion/accounting/entry/%d/pair', $in->getId()), ['_token' => $token, 'peer' => $out->getId()]);
        $this->em()->clear();
        $this->assertNull($this->em()->find(AccountEntry::class, $in->getId())->getTransferPeer(), 'Importes distintos no se emparejan, venga de donde venga el POST.');
    }

    public function testAnotarLaMitadQueFaltaLaCreaEnlazadaEnLaOtraCuenta(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$category, $box, $bank] = $this->catalog();
        $in = $this->entry($bank, $category, '2090-05-24', '400.00');
        $this->em()->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $in->getId()));
        $this->assertSelectorTextContains('body', 'No hay ninguna mitad suelta');
        $client->submit($crawler->filter('form[action$="/complete"]')->form(['account' => (string) $box->getId()]));
        $this->assertResponseRedirects();

        $this->em()->clear();
        $peer = $this->em()->find(AccountEntry::class, $in->getId())->getTransferPeer();
        $this->assertNotNull($peer);
        $this->assertSame($box->getId(), $peer->getAccount()->getId());
        $this->assertSame('-400.00', $peer->getAmount());
        $this->assertSame('2090-05-24', $peer->getDate()->format('Y-m-d'));
    }

    /**
     * @return array{0: BudgetCategory, 1: FinancialAccount, 2: FinancialAccount}
     */
    private function catalog(): array
    {
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        $group = (new BudgetCategoryGroup())->setName('TRASPASOS PRUEBA '.$suffix)->setKind(BudgetCategoryGroup::KIND_TRANSFER)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Traspaso entre cuentas')->setSortOrder(1);
        $box = (new FinancialAccount())->setName('Caja prueba '.$suffix)->setKind(FinancialAccount::KIND_CASH)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2090-01-01'))->setActive(true)->setSortOrder(99);
        $bank = (new FinancialAccount())->setName('Banco prueba '.$suffix)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2090-01-01'))->setActive(true)->setSortOrder(99);
        array_map([$em, 'persist'], [$group, $category, $box, $bank]);
        $em->flush();
        array_push($this->created, [BudgetCategoryGroup::class, $group->getId()], [BudgetCategory::class, $category->getId()],
            [FinancialAccount::class, $box->getId()], [FinancialAccount::class, $bank->getId()]);

        return [$category, $box, $bank];
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount): AccountEntry
    {
        $entry = (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept('Traspaso de prueba')->setAmount($amount);
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
