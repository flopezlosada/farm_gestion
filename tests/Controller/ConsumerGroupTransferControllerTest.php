<?php

namespace App\Tests\Controller;

use App\Entity\BankPayerAlias;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\ConsumerGroupTransfer;
use App\Entity\FinancialAccount;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Entity\Setting;
use App\Service\Accounting\Norma43\Norma43Movement;
use App\Service\Accounting\Remittance\PartnerNameMatcher;
use App\Service\AppSettings;
use App\Service\ConsumerGroup\ConsumerGroupTransfers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Las transferencias del grupo de consumo, de punta a punta sobre la base de tests: el
 * cruce asigna sola la que no deja duda; la pantalla deja asignar a mano las demás,
 * aprende al ordenante y anota el movimiento en la partida del grupo de consumo;
 * deshacer devuelve el pedido a pendiente y el cruce no lo rehace; apartar la quita de
 * la lista. Y sin el grupo de consumo, la pantalla no existe.
 *
 * Rondas y movimientos en 2096, con productor y cuenta propios, para no cruzarse con
 * nada. Nombres inventados.
 */
class ConsumerGroupTransferControllerTest extends AbstractAuthenticatedTest
{
    private ?int $producerId = null;

    private ?int $accountId = null;

    /** @var list<int> */
    private array $partnerIds = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        if ($this->accountId !== null) {
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupTransfer t WHERE t.line IN (SELECT l.id FROM App\Entity\BankStatementLine l WHERE l.account = :a)')->execute(['a' => $this->accountId]);
            $em->createQuery('DELETE FROM App\Entity\BankStatementLine l WHERE l.account = :a')->execute(['a' => $this->accountId]);
            $em->createQuery('DELETE FROM App\Entity\AccountEntry e WHERE e.account = :a')->execute(['a' => $this->accountId]);
            $em->createQuery('DELETE FROM App\Entity\FinancialAccount a WHERE a.id = :a')->execute(['a' => $this->accountId]);
        }
        if ($this->producerId !== null) {
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupOrder o WHERE o.round IN (SELECT r.id FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p)')->execute(['p' => $this->producerId]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupRoundItem i WHERE i.round IN (SELECT r.id FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p)')->execute(['p' => $this->producerId]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p')->execute(['p' => $this->producerId]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupProduct x WHERE x.producer = :p')->execute(['p' => $this->producerId]);
            $em->createQuery('DELETE FROM App\Entity\Producer p WHERE p.id = :p')->execute(['p' => $this->producerId]);
        }
        if ($this->partnerIds !== []) {
            $em->createQuery('DELETE FROM App\Entity\BankPayerAlias a WHERE a.partner IN (:ids)')->execute(['ids' => $this->partnerIds]);
            $em->createQuery('DELETE FROM App\Entity\Partner p WHERE p.id IN (:ids)')->execute(['ids' => $this->partnerIds]);
        }
        foreach ($em->getRepository(Setting::class)->findBy(['name' => [AppSettings::FEATURE_CONTABILIDAD, AppSettings::FEATURE_GRUPO_CONSUMO]]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testSinElGrupoDeConsumoLaPantallaNoExiste(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(false);

        $client->request('GET', '/gestion/accounting/consumer-group-transfers');

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * El cruce asigna sola la que debe justo un pedido y viene de la familia, aunque el
     * banco corte el nombre; la de un familiar sin ficha queda para elegir.
     */
    public function testElCruceAsignaSolaLaQueNoDejaDuda(): void
    {
        $this->createAuthenticatedClient();
        $this->enable(true);
        $this->ensureConsumerGroupCategory();
        [$lucia, $pedro] = $this->roundWithOrders();
        $sure = $this->transfer(1000, 'LUCIA ORTEGA PE');
        $unknown = $this->transfer(1500, 'TOMASA GIL ROMERO');

        $this->assertGreaterThanOrEqual(1, $this->matchAll());

        $this->assertSame(ConsumerGroupPaymentMethod::Transfer, $this->reload($lucia)->getPaidVia());
        $this->assertSame($sure->getId(), $this->reload($lucia)->getTransfer()?->getLine()->getId());
        $this->assertTrue($this->reload($lucia)->getTransfer()?->isAutomatic());
        $this->assertFalse($this->reload($pedro)->isPaid());
        $this->assertNull($this->em()->getRepository(ConsumerGroupTransfer::class)->findOneBy(['line' => $unknown->getId()]));
        $this->assertNotNull($this->em()->find(BankStatementLine::class, $sure->getId())->getEntry(), 'Sin apunte en el libro: se anota en la partida del grupo de consumo.');
        $this->assertSame('Grupo de consumo', $this->em()->find(BankStatementLine::class, $sure->getId())->getEntry()->getCategory()?->getName());
    }

    /** El ordenante que escribiría el banco si pagara la socia de ese pedido. */
    private function payerOf(ConsumerGroupOrder $order): string
    {
        return strtoupper($order->getPartner()->getName() . ' ' . $order->getPartner()->getSurname());
    }

    /**
     * Asigna el movimiento al pedido elegido en el buscador «otro pedido». El buscador
     * rellena su campo oculto con JavaScript, que el cliente de pruebas no ejecuta: se
     * envía el mismo formulario, con su token.
     */
    private function assignByOther(KernelBrowser $client, BankStatementLine $line, ConsumerGroupOrder $order): void
    {
        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $token = $crawler->filter(sprintf('#asignar-%d input[name="_token"]', $line->getId()))->attr('value');
        $client->request('POST', sprintf('/gestion/accounting/consumer-group-transfers/lines/%d/assign', $line->getId()), ['_token' => $token, 'other' => (string) $order->getId()]);
    }

    /**
     * Asignar a mano un importe que no cuadra avisa y NO aprende el ordenante: puede ser
     * un pago parcial o un error, y aprenderlo casaría mal la próxima vez.
     */
    public function testAsignarUnImporteQueNoCuadraAvisaYNoAprende(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        [$lucia, $pedro] = $this->roundWithOrders();
        $payer = $this->payerOf($lucia);
        $line = $this->transfer(1500, $payer);

        $this->assignByOther($client, $line, $pedro);
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'no cuadra: la transferencia trae 15,00 € y los pedidos suman 10,00 €');
        $this->assertSame(ConsumerGroupPaymentMethod::Transfer, $this->reload($pedro)->getPaidVia());
        $this->assertNull($this->em()->getRepository(BankPayerAlias::class)->findOneBy(['payerKey' => 'tomasa gil romero']));
    }

    /**
     * Asignar a mano paga el pedido y aprende al ordenante: la próxima transferencia
     * suya casa sola. Deshacer lo devuelve todo y el cruce no lo vuelve a asignar.
     */
    public function testAsignarAManoAprendeYDeshacerLoDevuelve(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        [, $pedro] = $this->roundWithOrders();
        $line = $this->transfer(1000, 'TOMASA GIL ROMERO');

        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(sprintf('#movimiento-%d', $line->getId()), 'TOMASA GIL ROMERO');
        $this->assertCount(1, $crawler->filter('#cgt-payable option[data-id="' . $pedro->getId() . '"]'), 'Los pedidos por pagar se pintan una sola vez, en la lista que comparten los buscadores.');
        $this->assignByOther($client, $line, $pedro);
        $this->assertResponseRedirects('/gestion/accounting/consumer-group-transfers');
        $client->followRedirect();
        $this->assertSelectorTextNotContains('body', 'no cuadra');

        $pedro = $this->reload($pedro);
        $this->assertSame(ConsumerGroupPaymentMethod::Transfer, $pedro->getPaidVia());
        $this->assertFalse($pedro->getTransfer()?->isAutomatic());
        $alias = $this->em()->getRepository(BankPayerAlias::class)->findOneBy(['payerKey' => 'tomasa gil romero']);
        $this->assertSame($pedro->getPartner()->getId(), $alias?->getPartner()->getId());

        // Deshacer: pendiente otra vez, el ordenante olvidado y el movimiento por elegir.
        $transferId = $pedro->getTransfer()->getId();
        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $client->submit($crawler->filter(sprintf('form[action$="/%d/release"]', $transferId))->form());
        $this->assertFalse($this->reload($pedro)->isPaid());
        $this->assertNull($this->em()->getRepository(BankPayerAlias::class)->findOneBy(['payerKey' => 'tomasa gil romero']));
        $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $this->assertSelectorExists(sprintf('#movimiento-%d', $line->getId()));

        $this->matchAll();
        $this->assertFalse($this->reload($pedro)->isPaid(), 'Lo que una persona deshizo no lo rehace el cruce.');
    }

    public function testApartarLaQuitaDeLaListaYRecuperarLaDevuelve(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        $this->roundWithOrders();
        $line = $this->transfer(1000, 'DONANTE GENEROSA SOLIDARIA');

        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $client->submit($crawler->filter(sprintf('form[action$="/lines/%d/dismiss"]', $line->getId()))->form());
        $client->followRedirect();
        $this->assertSelectorNotExists(sprintf('#movimiento-%d', $line->getId()));
        $this->assertSelectorTextContains('#apartadas', 'Apartadas');

        $transfer = $this->em()->getRepository(ConsumerGroupTransfer::class)->findOneBy(['line' => $line->getId()]);
        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $client->submit($crawler->filter(sprintf('form[action$="/%d/reopen"]', $transfer->getId()))->form());
        $client->followRedirect();
        $this->assertSelectorExists(sprintf('#movimiento-%d', $line->getId()));
    }

    /**
     * Apartar un movimiento que el cruce anotó solo en la partida del grupo de consumo
     * no toca el libro: avisa de que ese apunte hay que corregirlo desde el extracto.
     */
    public function testApartarUnMovimientoAnotadoSoloAvisaDelApunte(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        $this->ensureConsumerGroupCategory();
        [$lucia] = $this->roundWithOrders();
        $line = $this->transfer(1000, 'LUCIA ORTEGA PE');
        $this->matchAll();
        $transferId = $this->reload($lucia)->getTransfer()->getId();

        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $client->submit($crawler->filter(sprintf('form[action$="/%d/release"]', $transferId))->form());
        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $client->submit($crawler->filter(sprintf('form[action$="/lines/%d/dismiss"]', $line->getId()))->form());
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'Su apunte sigue en la partida «Grupo de consumo»');
        $this->assertNotNull($this->em()->find(BankStatementLine::class, $line->getId())->getEntry(), 'Apartar no borra el apunte.');
    }

    /**
     * Sólo se pide elegir lo que puede ser el pago de un pedido: debe justo uno o lo ordena
     * una socia. Un ingreso de una empresa, sea cual sea su importe, no tiene que ver con
     * el grupo de consumo.
     */
    public function testSoloSePideElegirLoQuePuedeSerUnPagoDelGrupo(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        [$lucia] = $this->roundWithOrders();
        $grande = $this->transfer(320000, 'ECOLISE');
        $pequeno = $this->transfer(2000, 'ECOLISE');
        $exacto = $this->transfer(1000, 'DONANTE GENEROSA SOLIDARIA');
        $socia = $this->transfer(3000, $this->payerOf($lucia));

        $client->request('GET', '/gestion/accounting/consumer-group-transfers');

        $this->assertSelectorNotExists(sprintf('#movimiento-%d', $grande->getId()));
        $this->assertSelectorNotExists(sprintf('#movimiento-%d', $pequeno->getId()), 'Una empresa con un importe pequeño tampoco.');
        $this->assertSelectorExists(sprintf('#movimiento-%d', $exacto->getId()), 'Debe justo un pedido.');
        $this->assertSelectorExists(sprintf('#movimiento-%d', $socia->getId()), 'La ordena una socia, aunque el importe no cuadre.');
    }

    /** Un pedido ya pagado no se puede volver a pagar con otra transferencia. */
    public function testNoSeAsignaUnPedidoYaPagado(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        [$lucia] = $this->roundWithOrders();
        $line = $this->transfer(1500, $this->payerOf($lucia));
        $crawler = $client->request('GET', '/gestion/accounting/consumer-group-transfers');
        $token = $crawler->filter(sprintf('#asignar-%d input[name="_token"]', $line->getId()))->attr('value');

        $em = $this->em();
        $em->find(ConsumerGroupOrder::class, $lucia->getId())->markPaid(ConsumerGroupPaymentMethod::Cash);
        $em->flush();

        $client->request('POST', sprintf('/gestion/accounting/consumer-group-transfers/lines/%d/assign', $line->getId()), ['_token' => $token, 'other' => (string) $lucia->getId()]);
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'ya está pagado o ya no se puede pagar');
        $this->assertSame(ConsumerGroupPaymentMethod::Cash, $this->reload($lucia)->getPaidVia());
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }

    /**
     * La partida «Ingresos / Grupo de consumo» donde el cruce anota los movimientos sin
     * apunte. La suite no carga datos de negocio: si no existe, se crea.
     */
    private function ensureConsumerGroupCategory(): void
    {
        $em = $this->em();
        if ($em->getRepository(BudgetCategory::class)->findOneByGroupAndName('INGRESOS', 'Grupo de consumo') !== null) {
            return;
        }
        $group = $em->getRepository(BudgetCategoryGroup::class)->findOneBy(['name' => 'INGRESOS'])
            ?? (new BudgetCategoryGroup())->setName('INGRESOS')->setKind(BudgetCategoryGroup::KIND_INCOME);
        $category = (new BudgetCategory())->setGroup($group)->setName('Grupo de consumo')->setSortOrder(99);
        $em->persist($group);
        $em->persist($category);
        $em->flush();
    }

    private function enable(bool $consumerGroup): void
    {
        $settings = static::getContainer()->get(AppSettings::class);
        $settings->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $settings->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, $consumerGroup);
    }

    /** El cruce automático, como lo llama la subida del extracto, y guardado. */
    private function matchAll(): int
    {
        $count = static::getContainer()->get(ConsumerGroupTransfers::class)->matchAll(null);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $count;
    }

    /**
     * Una ronda confirmada de marzo de 2096 con dos pedidos de 10 € (4 kg a 2,50): el
     * de Lucía y el de Pedro.
     *
     * @return array{0: ConsumerGroupOrder, 1: ConsumerGroupOrder}
     */
    private function roundWithOrders(): array
    {
        $em = $this->em();
        $producer = (new Producer())->setName('Huerta Transferencias ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $producer->addProduct($naranjas);
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_CONFIRMED);
        $round->setTitle('Ronda transferencias')->setProducer($producer)
            ->setOrdersCloseAt(new \DateTime('2096-03-05'))
            ->setDeliveryDate(new \DateTime('2096-03-10'));
        $round->setConfirmed(true);
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);
        $lucia = (new Partner())->setname('LUCIA')->setSurname('ORTEGA PEREZ ' . strtoupper(substr(uniqid(), -4)))->setStatus(Partner::STATUS_ACTIVO)->setMember(true);
        $pedro = (new Partner())->setname('PEDRO')->setSurname('MARTIN GIL ' . strtoupper(substr(uniqid(), -4)))->setStatus(Partner::STATUS_ACTIVO)->setMember(true);
        $orders = [];
        foreach ([$lucia, $pedro] as $partner) {
            $order = new ConsumerGroupOrder($round, $partner);
            $order->addLine(new ConsumerGroupOrderLine($order, $item, '4'));
            $round->addOrder($order);
            $em->persist($partner);
            $em->persist($order);
            $orders[] = $order;
        }
        array_map($em->persist(...), [$producer, $naranjas, $round, $item]);
        $em->flush();
        $this->producerId = $producer->getId();
        $this->partnerIds = [$lucia->getId(), $pedro->getId()];

        return $orders;
    }

    /** Una transferencia recibida en una cuenta propia del test, al estilo de La Caixa. */
    private function transfer(int $cents, string $payer): BankStatementLine
    {
        $em = $this->em();
        $account = $this->accountId !== null ? $em->find(FinancialAccount::class, $this->accountId) : null;
        if ($account === null) {
            $account = (new FinancialAccount())->setName('Banco transferencias ' . uniqid())->setKind(FinancialAccount::KIND_BANK)
                ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2090-01-01'))->setActive(true)->setSortOrder(99);
            $em->persist($account);
            $em->flush();
            $this->accountId = $account->getId();
        }
        $day = new \DateTimeImmutable('2096-03-08');
        $line = new BankStatementLine($account, new Norma43Movement($day, $day, '02', '000', $cents, substr(uniqid(), -10), '', '', ['TRANSF. A SU FAVOR', '14650100-' . $payer]), 1);
        $em->persist($line);
        $em->flush();

        return $line;
    }

    private function reload(ConsumerGroupOrder $order): ConsumerGroupOrder
    {
        return $this->em()->find(ConsumerGroupOrder::class, $order->getId());
    }
}
