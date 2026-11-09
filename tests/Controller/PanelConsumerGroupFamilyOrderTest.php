<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\ConsumerGroupUnit;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * El pedido del grupo de consumo es de la familia: si quien la encabeza ya ha pedido,
 * otro miembro lo ve en su ficha y, al cambiarlo, cambia ESE pedido en vez de abrir un
 * segundo. Era lo que pasaba antes: cada miembro pedía por su lado y la familia se
 * llevaba el doble.
 */
class PanelConsumerGroupFamilyOrderTest extends AbstractPartnerAuthenticatedTest
{
    private ?KernelBrowser $client = null;

    /** @var list<object> Lo creado por el test, para borrarlo al acabar. */
    private array $created = [];

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $em = $this->em();
            $em->clear();
            $this->socix()->setParent(null);
            foreach (array_reverse($this->created) as $entity) {
                $managed = $em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
    }

    public function testUnMiembroVeElPedidoQueHizoQuienEncabezaSuFamilia(): void
    {
        [$round] = $this->familiaConPedidoDeLaPrincipal('3');

        $crawler = $this->client->request('GET', '/panel/consumer-group/'.$round->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Es el pedido de tu familia: Ana también lo ve y lo puede cambiar.', $html);
        // La cantidad que pidió Ana sale ya escrita en el formulario del miembro.
        self::assertSame('3', $crawler->filter('input.cg-order-line__qty')->attr('value'));
    }

    public function testAlCambiarloSeCambiaElMismoPedidoYNoSeAbreOtro(): void
    {
        [$round, $item, $head] = $this->familiaConPedidoDeLaPrincipal('3');

        $crawler = $this->client->request('GET', '/panel/consumer-group/'.$round->getId());
        $token = $crawler->filter('form[data-cg-order] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/panel/consumer-group/'.$round->getId().'/order', [
            '_token' => $token,
            'quantity' => [$item->getId() => '5'],
        ]);

        self::assertResponseRedirects();
        $em = $this->em();
        $em->clear();
        $orders = $em->getRepository(ConsumerGroupOrder::class)->findBy(['round' => $round->getId()]);
        self::assertCount(1, $orders, 'La familia sigue teniendo un solo pedido.');
        self::assertSame($head->getId(), $orders[0]->getPartner()->getId());
        self::assertSame(5.0, (float) $orders[0]->getLines()->first()->getQuantity());
    }

    /**
     * Una ronda abierta en la que Ana, que encabeza la familia del socix de las
     * fixtures, ya ha pedido.
     *
     * @return array{0: ConsumerGroupRound, 1: ConsumerGroupRoundItem, 2: Partner}
     */
    private function familiaConPedidoDeLaPrincipal(string $quantity): array
    {
        $this->client = $this->createPartnerAuthenticatedClient();
        $this->client->getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $em = $this->em();

        $unit = $em->getRepository(ConsumerGroupUnit::class)->findOneBy(['name' => 'kg']);
        if ($unit === null) {
            $unit = (new ConsumerGroupUnit())->setName('kg');
            $em->persist($unit);
        }
        $producer = (new Producer())->setName('Huerta Familia '.uniqid());
        $product = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($unit);
        $producer->addProduct($product);
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $round->setTitle('Ronda familia')->setProducer($producer)
            ->setOrdersCloseAt(new \DateTime('+3 days'))->setDeliveryDate(new \DateTime('+7 days'));
        $item = new ConsumerGroupRoundItem($round, $product, '2.50');
        $round->addItem($item);

        $head = (new Partner())->setname('Ana')->setSurname('Familia '.uniqid());
        $head->addRelative($this->socix());
        $order = new ConsumerGroupOrder($round, $head);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, $quantity));

        foreach ([$producer, $product, $round, $item, $head, $order] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        // Se borra en orden inverso: el pedido antes que la ronda y la socia.
        $this->created = [$producer, $product, $head, $round, $order];

        return [$round, $item, $head];
    }

    private function socix(): Partner
    {
        return $this->em()->getRepository(Partner::class)->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
    }

    private function em(): EntityManagerInterface
    {
        return $this->client->getContainer()->get('doctrine.orm.entity_manager');
    }
}
