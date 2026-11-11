<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupPaymentState;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Entity\Remittance;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * La remesa del grupo de consumo por HTTP: sólo existe con el grupo de consumo
 * encendido; prepararla cobra a quien tiene recibo; enviarla NO cobra sus pedidos
 * (falta el abono del banco) y deja de poder tocarse su pago a mano; y si un pedido
 * cambia después del borrador, no se deja enviar.
 *
 * Rondas en marzo de 2096 y un productor propio para no cruzarse con nada.
 */
class ConsumerGroupRemittanceControllerTest extends AbstractAuthenticatedTest
{
    private ?int $producerId = null;

    /** @var list<int> */
    private array $partnerIds = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        if ($this->producerId !== null) {
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupOrder o WHERE o.round IN (SELECT r.id FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p)')->execute(['p' => $this->producerId]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupRoundItem i WHERE i.round IN (SELECT r.id FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p)')->execute(['p' => $this->producerId]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p')->execute(['p' => $this->producerId]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupProduct x WHERE x.producer = :p')->execute(['p' => $this->producerId]);
        }
        $em->createQuery('DELETE FROM App\Entity\Remittance r WHERE r.kind = :k AND r.period = :m')->execute(['k' => Remittance::KIND_CONSUMER_GROUP, 'm' => '2096-03-01']);
        if ($this->producerId !== null) {
            $em->createQuery('DELETE FROM App\Entity\Producer p WHERE p.id = :p')->execute(['p' => $this->producerId]);
        }
        if ($this->partnerIds !== []) {
            $em->createQuery('DELETE FROM App\Entity\Partner p WHERE p.id IN (:ids)')->execute(['ids' => $this->partnerIds]);
        }
        foreach ($em->getRepository(Setting::class)->findBy(['name' => [AppSettings::FEATURE_CONTABILIDAD, AppSettings::FEATURE_GRUPO_CONSUMO]]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }

    private function enable(bool $consumerGroup): void
    {
        $settings = static::getContainer()->get(AppSettings::class);
        $settings->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $settings->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, $consumerGroup);
    }

    /**
     * Una ronda confirmada que se entrega el 10 de marzo con un pedido de 10 € (4 kg a
     * 2,50) de una socia con recibo y otro de una sin mandato.
     *
     * @return array{0: ConsumerGroupOrder, 1: ConsumerGroupOrder} el de la socia con recibo y el de la otra
     */
    private function roundWithOrders(): array
    {
        $em = $this->em();
        $producer = (new Producer())->setName('Huerta Remesa ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $producer->addProduct($naranjas);
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_CONFIRMED);
        $round->setTitle('Ronda remesa')->setProducer($producer)
            ->setOrdersCloseAt(new \DateTime('2096-03-05'))
            ->setDeliveryDate(new \DateTime('2096-03-10'));
        $round->setConfirmed(true);
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);
        $ana = (new Partner())->setname('Ana')->setSurname('Remesa ' . uniqid())
            ->setIban('ES91 2100 0418 4502 0005 1332')
            ->setMandateReference('CGHTTP ' . uniqid())
            ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $bea = (new Partner())->setname('Bea')->setSurname('Remesa ' . uniqid());
        $orders = [];
        foreach ([$ana, $bea] as $partner) {
            $order = new ConsumerGroupOrder($round, $partner);
            $order->addLine(new ConsumerGroupOrderLine($order, $item, '4'));
            // También en la ronda: la primera petición del test reusa esta misma
            // ronda en memoria y, sin esto, la ficha la vería sin pedidos.
            $round->addOrder($order);
            $em->persist($partner);
            $em->persist($order);
            $orders[] = $order;
        }
        array_map($em->persist(...), [$producer, $naranjas, $round, $item]);
        $em->flush();
        $this->producerId = $producer->getId();
        $this->partnerIds = [$ana->getId(), $bea->getId()];

        return [$orders[0], $orders[1]];
    }

    /** Prepara el borrador de marzo de 2096 desde la lista de remesas y devuelve su ficha. */
    private function prepare(KernelBrowser $client): Remittance
    {
        $crawler = $client->request('GET', '/gestion/accounting/remittances');
        $form = $crawler->filter('form[action$="/from-consumer-group"]')->form();
        $client->submit($form, ['period' => '2096-03']);
        $this->assertResponseRedirects();

        return $this->em()->getRepository(Remittance::class)->findOneBy(['kind' => Remittance::KIND_CONSUMER_GROUP, 'period' => new \DateTimeImmutable('2096-03-01')]);
    }

    private function reload(ConsumerGroupOrder $order): ConsumerGroupOrder
    {
        return $this->em()->find(ConsumerGroupOrder::class, $order->getId());
    }

    public function testSinElGrupoDeConsumoNoHayRemesaDelGrupo(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(false);

        $client->request('GET', '/gestion/accounting/remittances');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form[action$="/from-consumer-group"]');

        $client->request('POST', '/gestion/accounting/remittances/from-consumer-group', ['period' => '2096-03']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testEnviarDejaElPedidoEnElReciboEsperandoAlBanco(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        [$ana, $bea] = $this->roundWithOrders();

        $remittance = $this->prepare($client);

        $this->assertCount(1, $remittance->getLines(), 'Bea no tiene mandato: no lleva recibo.');
        $this->assertSame('10.00', $remittance->getLines()->first()->getAmount());

        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $remittance->getId()));
        $client->submit($crawler->filter('form[action$="/send"]')->form(), ['charge_date' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);

        $this->assertTrue($this->em()->find(Remittance::class, $remittance->getId())->isSent());
        $this->assertFalse($this->reload($ana)->isPaid(), 'Enviada no es cobrada: falta que el abono entre en el banco.');
        $this->assertSame(ConsumerGroupPaymentState::AwaitingBank, $this->reload($ana)->getPaymentState());
        $this->assertFalse($this->reload($bea)->isPaid(), 'Lo de Bea sigue pendiente: lo marca la comisión.');

        // Su pago ya no se toca a mano: ni se ofrece en la ficha de la ronda.
        $client->request('GET', sprintf('/gestion/consumer-group/%d', $ana->getRound()->getId()));
        $this->assertSelectorNotExists(sprintf('form[action$="/orders/%d/payment"]', $ana->getId()));
        $this->assertSelectorExists(sprintf('form[action$="/orders/%d/payment"]', $bea->getId()));
        $this->assertSelectorTextContains('body', 'En el recibo, falta el abono');
    }

    public function testUnPedidoPagadoAManoDespuesDelBorradorNoDejaEnviarla(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        [$ana] = $this->roundWithOrders();
        $remittance = $this->prepare($client);

        // Ana paga en metálico antes de que salga la remesa.
        $crawler = $client->request('GET', sprintf('/gestion/consumer-group/%d', $ana->getRound()->getId()));
        $token = $crawler->filter(sprintf('form[action$="/orders/%d/payment"] input[name="_token"]', $ana->getId()))->attr('value');
        $client->request('POST', sprintf('/gestion/consumer-group/orders/%d/payment', $ana->getId()), ['_token' => $token, 'via' => 'cash']);
        $this->assertSame(ConsumerGroupPaymentMethod::Cash, $this->reload($ana)->getPaidVia());

        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d', $remittance->getId()));
        $this->assertStringContainsString('el recibo cobra 10,00 € y sus pedidos deben ahora 0,00 €', $crawler->text());
        $client->submit($crawler->filter('form[action$="/send"]')->form(), ['charge_date' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);

        $this->assertTrue($this->em()->find(Remittance::class, $remittance->getId())->isDraft(), 'No se envía: le cobraría dos veces.');
        $this->assertSame(ConsumerGroupPaymentMethod::Cash, $this->reload($ana)->getPaidVia());
    }

    public function testLaComisionNoPuedeMarcarAManoQueSePagoPorRecibo(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enable(true);
        [, $bea] = $this->roundWithOrders();

        $crawler = $client->request('GET', sprintf('/gestion/consumer-group/%d', $bea->getRound()->getId()));
        $token = $crawler->filter(sprintf('form[action$="/orders/%d/payment"] input[name="_token"]', $bea->getId()))->attr('value');
        $client->request('POST', sprintf('/gestion/consumer-group/orders/%d/payment', $bea->getId()), ['_token' => $token, 'via' => 'receipt']);

        $this->assertFalse($this->reload($bea)->isPaid());
    }
}
