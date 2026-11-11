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
 * La portada del panel enseña el grupo de consumo: los pedidos abiertos en los que
 * aún se puede apuntar y lo que su familia tiene por recoger, con su importe y si
 * está pagado. Antes sólo vivía en su propia pantalla y un pedido abierto podía
 * cerrarse sin que la socia lo viera.
 */
class PanelHomeConsumerGroupTest extends AbstractPartnerAuthenticatedTest
{
    private ?KernelBrowser $client = null;

    /** @var list<object> Lo creado por el test, en orden de borrado. */
    private array $created = [];

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $em = $this->em();
            $em->clear();
            $this->socix()->setParent(null);
            foreach ($this->created as $entity) {
                $managed = $em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }

        parent::tearDown();
    }

    /**
     * Un pedido abierto en el que no ha pedido nadie de su familia sale como
     * invitación a apuntarse.
     */
    public function testUnPedidoAbiertoSinApuntarSaleParaApuntarse(): void
    {
        $title = $this->ronda(confirmed: false, closesIn: '+3 days', familyQuantity: null);

        $html = $this->portada();

        self::assertStringContainsString('<h2 class="pnl-block__title">Grupo de consumo</h2>', $html);
        self::assertSame(1, substr_count($html, $title));
        self::assertStringContainsString('Apuntarme', $html);
    }

    /**
     * Abierto, ya confirmado y con pedido de la familia: sale UNA vez, como algo por
     * recoger con su importe y su estado de pago, no también como abierto.
     */
    public function testLoQueLaFamiliaRecogeSaleUnaVezConImporteYPago(): void
    {
        $title = $this->ronda(confirmed: true, closesIn: '+3 days', familyQuantity: '4');

        $html = $this->portada();

        self::assertSame(1, substr_count($html, $title), 'La ronda no se repite como abierta y como por recoger.');
        self::assertStringContainsString('Recoges el', $html);
        // 4 kg a 2,50 €; quien encabeza la familia no tiene mandato, así que falta pagar.
        self::assertStringContainsString('10,00 €', $html);
        self::assertStringContainsString('Pendiente de pago', $html);
        self::assertStringNotContainsString('Ya apuntada', $html);
    }

    /**
     * Con el plazo vencido ya no se puede pedir: en la portada sería invitar a algo
     * imposible, así que el abierto no sale.
     */
    public function testUnAbiertoConElPlazoVencidoNoSale(): void
    {
        $title = $this->ronda(confirmed: false, closesIn: '-1 day', familyQuantity: null);

        self::assertStringNotContainsString($title, $this->portada());
    }

    /**
     * Sin el grupo de consumo activado no hay bloque, aunque haya pedidos abiertos.
     */
    public function testSinLaFuncionalidadNoHayBloque(): void
    {
        $title = $this->ronda(confirmed: false, closesIn: '+3 days', familyQuantity: null);
        $this->client->getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, false);

        $html = $this->portada();

        self::assertStringNotContainsString($title, $html);
        self::assertStringNotContainsString('<h2 class="pnl-block__title">Grupo de consumo</h2>', $html);
    }

    /**
     * La portada del socix de las fixtures.
     *
     * @return string el HTML
     */
    private function portada(): string
    {
        $this->client->request('GET', '/panel');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Una ronda abierta con entrega dentro de una semana y, si se pide, el pedido de
     * Ana, que encabeza la familia del socix de las fixtures.
     *
     * @param bool        $confirmed      si la ronda ya es firme
     * @param string      $closesIn       cierre de apuntes, relativo a hoy
     * @param string|null $familyQuantity kg que pide la familia (null = no pide)
     *
     * @return string el título de la ronda, único por test
     */
    private function ronda(bool $confirmed, string $closesIn, ?string $familyQuantity): string
    {
        $this->client = $this->createPartnerAuthenticatedClient();
        $this->client->getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $em = $this->em();

        $unit = $em->getRepository(ConsumerGroupUnit::class)->findOneBy(['name' => 'kg']);
        if ($unit === null) {
            $unit = (new ConsumerGroupUnit())->setName('kg');
            $em->persist($unit);
        }
        $title = 'Ronda portada '.uniqid();
        $producer = (new Producer())->setName('Huerta Portada '.uniqid());
        $product = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($unit);
        $producer->addProduct($product);
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $round->setTitle($title)->setProducer($producer)->setConfirmed($confirmed)
            ->setOrdersCloseAt(new \DateTime($closesIn))->setDeliveryDate(new \DateTime('+7 days'));
        $item = new ConsumerGroupRoundItem($round, $product, '2.50');
        $round->addItem($item);
        $entities = [$producer, $product, $round, $item];

        $head = null;
        $order = null;
        if ($familyQuantity !== null) {
            $head = (new Partner())->setname('Ana')->setSurname('Portada '.uniqid());
            $head->addRelative($this->socix());
            $order = new ConsumerGroupOrder($round, $head);
            $order->addLine(new ConsumerGroupOrderLine($order, $item, $familyQuantity));
            array_push($entities, $head, $order);
        }

        foreach ($entities as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        // El pedido antes que la ronda y la socia, la ronda antes que su productor.
        $this->created = array_values(array_filter([$order, $round, $head, $product, $producer]));

        return $title;
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
