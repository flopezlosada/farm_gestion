<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\PendingTransfer;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\Accounting\TransferRecorder;
use App\Service\AppSettings;
use App\Service\Telegram\Destination\TransferDestination;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Los traspasos que llegan por el bot: se ven en su lista, se confirman en el
 * formulario de traspaso ya relleno (y sólo entonces entran en el libro), se descartan
 * sin tocarlo, y el «Es otra cosa» del bot no deshace uno que ya está en el libro.
 *
 * Autocontenido: cuentas y partida de traspasos propias, en 2090.
 */
class PendingTransferControllerTest extends AbstractAuthenticatedTest
{
    /** @var list<array{class-string, int}> Lo creado, para borrarlo al acabar. */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $accounts = array_map(static fn (array $row): int => $row[1], array_filter($this->created, static fn (array $row): bool => $row[0] === FinancialAccount::class));
        foreach ($em->getRepository(PendingTransfer::class)->findBy(['from' => $accounts]) as $pending) {
            $em->remove($pending);
        }
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

    /** Lo que no se entendió se completa al confirmar; hasta entonces el libro no lo ve. */
    public function testConfirmarLoAnotaEnElLibroConLoCorregido(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$box, $field] = $this->catalog();
        $pending = $this->pending($box, null, null);

        $crawler = $client->request('GET', '/gestion/accounting/transfer/pending/');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(sprintf('a[href="/gestion/accounting/transfer/pending/%d"]', $pending->getId())));
        $this->assertSame([], $this->entriesOf($box), 'Sin confirmar no hay apuntes.');

        $crawler = $client->request('GET', sprintf('/gestion/accounting/transfer/pending/%d', $pending->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'por el bot');
        $form = $crawler->selectButton('Anotar el traspaso')->form();
        $this->assertSame((string) $box->getId(), $form['account_transfer[from]']->getValue(), 'Llega relleno con lo entendido.');
        $this->assertSame('2090-03-02', $form['account_transfer[date]']->getValue());

        $client->submit($form, ['account_transfer[to]' => (string) $field->getId(), 'account_transfer[amount]' => '50']);
        $this->assertResponseRedirects('/gestion/accounting/transfer/pending/');

        $this->em()->clear();
        $out = $this->entriesOf($box);
        $this->assertCount(1, $out);
        $this->assertSame('-50.00', $out[0]->getAmount());
        $this->assertSame('Gasoil del tractor', $out[0]->getConcept());
        $this->assertSame('50.00', $out[0]->getTransferPeer()?->getAmount());
        $this->assertSame($out[0]->getId(), $this->em()->find(PendingTransfer::class, $pending->getId())->getEntry()?->getId());

        $client->request('GET', sprintf('/gestion/accounting/transfer/pending/%d', $pending->getId()));
        $this->assertResponseRedirects('/gestion/accounting/transfer/pending/', null, 'Confirmado no se anota dos veces.');
    }

    public function testDescartarNoTocaElLibro(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$box, $field] = $this->catalog();
        $pending = $this->pending($box, $field, '20.00');

        $crawler = $client->request('GET', '/gestion/accounting/transfer/pending/');
        $client->submit($crawler->filter(sprintf('form[action="/gestion/accounting/transfer/pending/%d/discard"]', $pending->getId()))->form());
        $this->assertResponseRedirects('/gestion/accounting/transfer/pending/');

        $this->em()->clear();
        $this->assertNull($this->em()->find(PendingTransfer::class, $pending->getId()));
        $this->assertSame([], $this->entriesOf($box));
    }

    /** Desde la portada: el enlace lleva al formulario relleno y descartar vuelve a la portada. */
    public function testDesdeLaPortadaSeRevisaODescarta(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$box, $field] = $this->catalog();
        $pending = $this->pending($box, $field, '20.00');

        $crawler = $client->request('GET', '/gestion/accounting/');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(sprintf('.acc-todo a[href="/gestion/accounting/transfer/pending/%d"]', $pending->getId())));
        $client->submit($crawler->filter(sprintf('.acc-todo form[action="/gestion/accounting/transfer/pending/%d/discard"]', $pending->getId()))->form());

        $this->assertResponseRedirects('/gestion/accounting/');
        $this->em()->clear();
        $this->assertNull($this->em()->find(PendingTransfer::class, $pending->getId()));
        $this->assertSame([], $this->entriesOf($box));
    }

    /**
     * «Es otra cosa» en el bot: sin confirmar se tira; ya en el libro, no se da por
     * deshecho (seguiría contando en el saldo). Y borrar el traspaso en la web se lo
     * lleva, para que el bot no hable de algo que ya no existe.
     */
    public function testElBotNoDeshaceLoQueYaEstaEnElLibro(): void
    {
        static::createClient();
        [$box, $field] = $this->catalog();
        $destination = static::getContainer()->get(TransferDestination::class);

        $loose = $this->pending($box, $field, '20.00');
        $looseId = $loose->getId(); // Doctrine pone a null el id de lo que borra.
        $this->assertNull($this->undo($destination, $loose));
        $this->assertNull($this->em()->find(PendingTransfer::class, $looseId));

        $confirmed = $this->pending($box, $field, '30.00');
        $recorder = static::getContainer()->get(TransferRecorder::class);
        $out = $recorder->record($box, $field, '30.00', $confirmed->getDate());
        $confirmed->confirm($out);
        $this->em()->flush();
        $this->assertStringContainsString('ya está anotado', (string) $this->undo($destination, $confirmed));

        $recorder->remove($out);
        $this->em()->flush();
        $this->em()->clear();
        $this->assertNull($this->em()->find(PendingTransfer::class, $confirmed->getId()));
    }

    /** Como lo deshace el bot: dentro de una transacción, que es lo que pide su bloqueo. */
    private function undo(TransferDestination $destination, PendingTransfer $transfer): ?string
    {
        return $this->em()->wrapInTransaction(static fn (): ?string => $destination->undo((string) $transfer->getId()));
    }

    /**
     * Partida de traspasos y dos cajas, abiertas en 2090.
     *
     * @return array{0: FinancialAccount, 1: FinancialAccount}
     */
    private function catalog(): array
    {
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        $group = (new BudgetCategoryGroup())->setName('TRASPASOS PRUEBA '.$suffix)->setKind(BudgetCategoryGroup::KIND_TRANSFER)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Traspaso entre cuentas')->setSortOrder(1);
        $box = $this->cash('Caja local prueba '.$suffix);
        $field = $this->cash('Caja huerta prueba '.$suffix);
        array_map([$em, 'persist'], [$group, $category, $box, $field]);
        $em->flush();
        array_push($this->created, [BudgetCategoryGroup::class, $group->getId()], [BudgetCategory::class, $category->getId()],
            [FinancialAccount::class, $box->getId()], [FinancialAccount::class, $field->getId()]);

        return [$box, $field];
    }

    private function cash(string $name): FinancialAccount
    {
        return (new FinancialAccount())->setName($name)->setKind(FinancialAccount::KIND_CASH)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2090-01-01'))->setActive(true)->setSortOrder(99);
    }

    private function pending(FinancialAccount $from, ?FinancialAccount $to, ?string $amount): PendingTransfer
    {
        $admin = $this->em()->getRepository(User::class)->loadUserByIdentifier('admin');
        $pending = (new PendingTransfer($admin, new \DateTimeImmutable('2090-03-02')))
            ->setAccounts($from, $to)->setAmount($amount)->setConcept('Gasoil del tractor');
        $this->em()->persist($pending);
        $this->em()->flush();

        return $pending;
    }

    /** @return list<AccountEntry> */
    private function entriesOf(FinancialAccount $account): array
    {
        return $this->em()->getRepository(AccountEntry::class)->findBy(['account' => $account->getId()]);
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
