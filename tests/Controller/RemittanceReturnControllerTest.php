<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Entity\ReturnStatus;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Los recibos devueltos por HTTP: la devolución del banco sale en su remesa con los
 * recibos de su importe, se elige cuál fue, queda como deuda abierta y se cierra con
 * una de las salidas (volver a cobrar en un borrador, pagado aparte, no se debía).
 *
 * Los apuntes llevan los conceptos tal como los pone Fiare. Autocontenido: cuenta,
 * partida y remesas propias, en 2095.
 */
class RemittanceReturnControllerTest extends AbstractAuthenticatedTest
{
    private const IBAN = 'ES9121000418450200051332';
    private const REFERENCE = '20950016561';

    /** @var list<array{class-string, int}> Lo creado, para borrarlo al acabar. */
    private array $created = [];

    protected function tearDown(): void
    {
        try {
            $this->cleanUp();
        } finally {
            // Aunque la limpieza falle, el kernel se apaga: si no, el test siguiente
            // no puede crear su cliente.
            parent::tearDown();
        }
    }

    private function cleanUp(): void
    {
        $em = $this->em();
        $em->clear();
        $em->createQuery(sprintf('DELETE FROM %s x WHERE x.line IN (SELECT l.id FROM %s l JOIN l.remittance r WHERE r.period >= :from)', RemittanceReturn::class, RemittanceLine::class))
            ->setParameter('from', new \DateTimeImmutable('2095-01-01'))
            ->execute();
        foreach ($em->getRepository(Remittance::class)->findAll() as $remittance) {
            if ($remittance->getPeriod()->format('Y') === '2095') {
                $em->remove($remittance);
            }
        }
        $em->flush();
        $accounts = array_column(array_filter($this->created, static fn (array $row): bool => $row[0] === FinancialAccount::class), 1);
        foreach ($em->getRepository(AccountEntry::class)->findBy(['account' => $accounts]) as $entry) {
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
    }

    /**
     * El circuito de «volver a cobrar»: la devolución sale con los dos recibos de su
     * importe (no el de otro importe, ni la devolución de otro lote), se liga al que
     * fue, se manda a volver a cobrar, entra en el borrador siguiente y, al enviarlo,
     * queda resuelta.
     */
    public function testUnaDevolucionSeLigaASuReciboYSeVuelveACobrar(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$august, $bank, $category] = $this->augustWithBank();
        $returned = $this->entry($bank, $category, '2095-08-19', '-55.00', 'bel-liq.rem.devol' . self::REFERENCE);
        $this->entry($bank, $category, '2095-08-20', '-55.00', 'bel-liq.rem.devol20950099999');
        $this->em()->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $august->getId()));
        $this->assertResponseIsSuccessful();
        $forms = $crawler->filter('form[action="/gestion/accounting/returns/new"]');
        $bankForm = $forms->reduce(static fn ($f): bool => $f->filter('input[name="entry"]')->count() === 1);
        $this->assertCount(1, $bankForm, 'Sólo la devolución de un lote del mes de la remesa.');
        $options = $bankForm->filter('select[name="line"] option[value!=""]');
        $this->assertSame(['ZZTEST ANA', 'ZZTEST LUIS'], array_map(static fn (string $t): string => explode(' · ', $t)[0], $options->each(static fn ($o): string => $o->text())));

        $luis = $this->line($august, 'ZZTEST LUIS');
        $client->submit($bankForm->form(['line' => (string) $luis->getId()]));
        $this->assertResponseRedirects();
        $return = $this->returnOf($luis);
        $this->assertSame($returned->getId(), $return->getEntry()?->getId());
        $this->assertSame(ReturnStatus::Pending, $return->getStatus());

        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $august->getId()));
        $this->assertSame(0, $crawler->filter('input[name="entry"]')->count(), 'Ligada, la devolución ya no se ofrece.');

        $this->resolve($client, $return, 'recharge');
        $this->assertSame(ReturnStatus::ToRecharge, $this->returnOf($luis)->getStatus());

        $draft = new Remittance(Remittance::KIND_OTHER, new \DateTimeImmutable('2095-09-01'), 'ZZTEST septiembre', Remittance::SOURCE_APP, null);
        $this->em()->persist($draft);
        $this->em()->flush();
        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $draft->getId()));
        $this->assertSelectorTextContains('body', 'por volver a cobrar');
        $client->submit($crawler->filter(sprintf('form[action="/gestion/accounting/returns/recharge-into/%d"]', $draft->getId()))->form());
        $this->assertResponseRedirects();

        $draft = $this->em()->find(Remittance::class, $draft->getId());
        $this->assertCount(1, $draft->getLines());
        $retry = $draft->getLines()->first();
        $this->assertSame('ZZTEST LUIS', $retry->getDebtorName());
        $this->assertSame('55.00', $retry->getAmount());
        $this->assertSame('AGOSTO 2095 901 DEV', $retry->getDebitReference());
        $this->assertSame(ReturnStatus::Recharging, $this->returnOf($luis)->getStatus());

        $draft = $this->em()->find(Remittance::class, $draft->getId());
        $draft->markSent(null, new \DateTimeImmutable('2026-09-18'));
        $this->em()->flush();
        $this->assertSame(ReturnStatus::Closed, $this->returnOf($luis)->getStatus());
        $client->request('GET', '/gestion/accounting/returns');
        $this->assertSelectorTextContains('body', 'Ningún recibo devuelto por resolver');
    }

    /**
     * «Pagado aparte» se liga al ingreso de su importe. Si se borra ese apunte, la
     * devolución vuelve sola a estar abierta: el pago que la cerraba ya no existe.
     */
    public function testPagadoAparteSeReabreSiSeBorraElPago(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$august, $bank, $category] = $this->augustWithBank();
        $this->entry($bank, $category, '2095-09-02', '36.00', 'trf. otro importe');
        $payment = $this->entry($bank, $category, '2095-09-03', '55.00', 'trf. cuota agosto');
        $this->em()->flush();
        $return = $this->markReturned($client, $august, 'ZZTEST ANA');

        $crawler = $client->request('GET', sprintf('/gestion/accounting/returns/%d/paid', $return->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSame([(string) $payment->getId()], $crawler->filter('input[name="entry"]')->each(static fn ($i): string => $i->attr('value')));
        $form = $crawler->filter('form')->reduce(static fn ($f): bool => $f->filter('input[name="entry"]')->count() > 0);
        $client->submit($form->form(['entry' => (string) $payment->getId()]));
        $this->assertResponseRedirects('/gestion/accounting/returns');
        $this->assertSame(ReturnStatus::Closed, $this->returnOf($this->line($august, 'ZZTEST ANA'))->getStatus());

        $em = $this->em();
        $em->remove($em->find(AccountEntry::class, $payment->getId()));
        $em->flush();
        $this->assertSame(ReturnStatus::Pending, $this->returnOf($this->line($august, 'ZZTEST ANA'))->getStatus());
    }

    /** Lo que se eligió se deshace; una pendiente se quita entera; y la remesa no se tira con devoluciones. */
    public function testDeshacerQuitarYNoTirarLaRemesa(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        [$august] = $this->augustWithBank();
        $return = $this->markReturned($client, $august, 'ZZTEST SOL');

        $this->resolve($client, $return, 'not_owed');
        $this->assertSame(ReturnStatus::Closed, $this->returnOf($this->line($august, 'ZZTEST SOL'))->getStatus());
        $this->resolve($client, $return, 'undo');
        $this->assertNotNull($this->returnOf($this->line($august, 'ZZTEST SOL')), 'Cerrada no se quita: antes se deshace.');

        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $august->getId()));
        $client->submit($crawler->filter(sprintf('form[action="/gestion/accounting/remittances/%d/delete"]', $august->getId()))->form());
        $this->assertNotNull($this->em()->find(Remittance::class, $august->getId()), 'Tirarla se llevaría sus devoluciones.');

        $this->resolve($client, $return, 'reopen');
        $this->assertSame(ReturnStatus::Pending, $this->returnOf($this->line($august, 'ZZTEST SOL'))->getStatus());
        $this->resolve($client, $return, 'undo');
        $this->assertNull($this->returnOf($this->line($august, 'ZZTEST SOL')));
    }

    /** Con sólo lectura se ven las devoluciones, pero no se marca nada. */
    public function testConLecturaNoSeMarcaNada(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD']));
        $this->enableModule();
        [$august] = $this->augustWithBank();

        $client->request('GET', '/gestion/accounting/returns');
        $this->assertResponseIsSuccessful();
        $client->request('POST', '/gestion/accounting/returns/new', ['line' => $this->line($august, 'ZZTEST ANA')->getId()]);
        $this->assertResponseStatusCodeSame(403);
    }

    /** Da un recibo por devuelto sin apunte desde la ficha de su remesa. */
    private function markReturned(KernelBrowser $client, Remittance $remittance, string $debtor): RemittanceReturn
    {
        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $remittance->getId()));
        $line = $this->line($remittance, $debtor);
        $form = $crawler->filter('form[action="/gestion/accounting/returns/new"]')
            ->reduce(static fn ($f): bool => $f->filter('input[name="entry"]')->count() === 0)
            ->form(['line' => (string) $line->getId()]);
        $client->submit($form);
        $this->assertResponseRedirects();

        return $this->returnOf($line);
    }

    /** Pulsa una de las salidas de la lista de devoluciones. */
    private function resolve(KernelBrowser $client, RemittanceReturn $return, string $action): void
    {
        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $return->getLine()->getRemittance()->getId()));
        $token = $crawler->filter(sprintf('form[action="/gestion/accounting/returns/%d/resolve"] input[name="_token"]', $return->getId()))->attr('value');
        $client->request('POST', sprintf('/gestion/accounting/returns/%d/resolve', $return->getId()), ['_token' => $token, 'action' => $action]);
        $this->assertResponseRedirects();
    }

    /**
     * La remesa de agosto ya enviada (dos recibos de 55 y uno de 36) y su abono en el
     * banco con la referencia del lote.
     *
     * @return array{0: Remittance, 1: FinancialAccount, 2: BudgetCategory}
     */
    private function augustWithBank(): array
    {
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        $group = (new BudgetCategoryGroup())->setName('CUOTAS PRUEBA ' . $suffix)->setKind(BudgetCategoryGroup::KIND_INCOME)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Cuotas')->setSortOrder(1);
        $bank = (new FinancialAccount())->setName('Banco prueba ' . $suffix)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2095-01-01'))->setActive(true)->setSortOrder(99);
        $lines = array_map(
            static fn (array $row): RemittanceLine => (new RemittanceLine())->setDebtorName($row[0])->setIban(self::IBAN)
                ->setMandateReference('CUOTA MENSUAL ' . $row[1])->setMandateSignedOn(new \DateTimeImmutable('2022-12-01'))
                ->setDebitReference('AGOSTO 2095 ' . $row[1])->setSequenceType(RemittanceLine::SEQUENCE_FIRST)->setAmount($row[2]),
            [['ZZTEST ANA', 900, '55.00'], ['ZZTEST LUIS', 901, '55.00'], ['ZZTEST SOL', 902, '36.00']],
        );
        $august = Remittance::fromImport(Remittance::KIND_MEMBERSHIP, new \DateTimeImmutable('2095-08-01'), 'ZZTEST agosto', 'agosto.xlsx', $lines, null);
        array_map([$em, 'persist'], [$group, $category, $bank, $august]);
        $em->flush();
        array_push($this->created, [BudgetCategoryGroup::class, $group->getId()], [BudgetCategory::class, $category->getId()], [FinancialAccount::class, $bank->getId()]);
        $this->entry($bank, $category, '2095-08-18', '146.00', 'ab.rem.' . self::REFERENCE);
        $em->flush();

        return [$august, $bank, $category];
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount, string $concept): AccountEntry
    {
        $entry = (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount);
        $this->em()->persist($entry);

        return $entry;
    }

    private function line(Remittance $remittance, string $debtor): RemittanceLine
    {
        return $this->em()->getRepository(RemittanceLine::class)->findOneBy(['remittance' => $remittance->getId(), 'debtorName' => $debtor]);
    }

    /** La devolución de un recibo, leída de la base y no de lo que haya en memoria. */
    private function returnOf(RemittanceLine $line): ?RemittanceReturn
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(RemittanceReturn::class)->findOneBy(['line' => $line->getId()]);
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
