<?php

namespace App\Tests\Repository;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\ConsumerGroupUnit;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Repository\ConsumerGroupOrderLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «Lo que pidió la última vez» contra la base: por PRODUCTO, lo más reciente de cada
 * uno aunque salga de pedidos distintos, sin contar lo vaciado ni el pedido en curso.
 * Pedidos en 2097 y productores propios para no cruzarse con nada.
 */
class ConsumerGroupOrderLineRepositoryTest extends KernelTestCase
{
    /** @var list<Producer> */
    private array $producers = [];

    private Partner $partner;

    private ConsumerGroupProduct $naranjas;

    private ConsumerGroupProduct $aceite;

    protected function setUp(): void
    {
        $em = $this->em();
        $unit = $em->getRepository(ConsumerGroupUnit::class)->findOneBy(['name' => 'kg']) ?? (new ConsumerGroupUnit())->setName('kg');
        $em->persist($unit);
        $this->naranjas = $this->product('Naranjas', $unit);
        $this->aceite = $this->product('Aceite', $unit);
        $this->partner = (new Partner())->setname('Ana')->setSurname('Última ' . uniqid());
        $em->persist($this->partner);
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        foreach ($this->producers as $producer) {
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupOrder o WHERE o.round IN (SELECT r.id FROM App\Entity\ConsumerGroupRound r JOIN r.producers rp WHERE rp.id = :p)')->execute(['p' => $producer->getId()]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupRound r WHERE :p MEMBER OF r.producers')->execute(['p' => $producer->getId()]);
            $em->createQuery('DELETE FROM App\Entity\ConsumerGroupProduct p WHERE p.producer = :p')->execute(['p' => $producer->getId()]);
            $em->createQuery('DELETE FROM App\Entity\Producer p WHERE p.id = :p')->execute(['p' => $producer->getId()]);
        }
        $em->createQuery('DELETE FROM App\Entity\Partner p WHERE p.id = :id')->execute(['id' => $this->partner->getId()]);
        parent::tearDown();
    }

    public function testCadaProductoTraeLoMasRecienteAunqueVengaDePedidosDistintos(): void
    {
        // Marzo: naranjas 2 y aceite 1. Junio: solo naranjas, 5. Lo último de cada cosa:
        // naranjas de junio, aceite de marzo.
        $this->order('2097-03-10', ['naranjas' => '2', 'aceite' => '1']);
        $this->order('2097-06-10', ['naranjas' => '5']);
        $this->em()->flush();

        $found = $this->repo()->findLastQuantitiesByProduct($this->partner, [$this->naranjas->getId(), $this->aceite->getId()]);

        self::assertEqualsWithDelta(5.0, (float) $found[$this->naranjas->getId()], 0.001);
        self::assertEqualsWithDelta(1.0, (float) $found[$this->aceite->getId()], 0.001);
    }

    public function testUnPedidoVaciadoNoGanaAlAnterior(): void
    {
        $this->order('2097-03-10', ['naranjas' => '2']);
        $this->order('2097-06-10', ['naranjas' => '0']);
        $this->em()->flush();

        $found = $this->repo()->findLastQuantitiesByProduct($this->partner, [$this->naranjas->getId()]);

        self::assertEqualsWithDelta(2.0, (float) $found[$this->naranjas->getId()], 0.001);
    }

    public function testElPedidoEnCursoNoCuentaComoAnterior(): void
    {
        $this->order('2097-03-10', ['naranjas' => '2']);
        $enCurso = $this->order('2097-06-10', ['naranjas' => '9']);
        $this->em()->flush();

        $found = $this->repo()->findLastQuantitiesByProduct($this->partner, [$this->naranjas->getId()], $enCurso->getRound()->getId());

        self::assertEqualsWithDelta(2.0, (float) $found[$this->naranjas->getId()], 0.001);
    }

    public function testSoloDevuelveLosProductosPedidosYSinProductosNoConsulta(): void
    {
        $this->order('2097-03-10', ['naranjas' => '2']);
        $this->em()->flush();

        $found = $this->repo()->findLastQuantitiesByProduct($this->partner, [$this->naranjas->getId(), $this->aceite->getId()]);

        self::assertSame([$this->naranjas->getId()], array_keys($found));
        self::assertSame([], $this->repo()->findLastQuantitiesByProduct($this->partner, []));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function repo(): ConsumerGroupOrderLineRepository
    {
        return static::getContainer()->get(ConsumerGroupOrderLineRepository::class);
    }

    /** Producto de un productor nuevo: cada producto el suyo, como en un pedido con varios. */
    private function product(string $name, ConsumerGroupUnit $unit): ConsumerGroupProduct
    {
        $producer = (new Producer())->setName('Productor ' . $name . ' ' . uniqid());
        $product = (new ConsumerGroupProduct())->setName($name)->setUnit($unit);
        $producer->addProduct($product);
        $this->em()->persist($producer);
        $this->em()->persist($product);
        $this->producers[] = $producer;

        return $product;
    }

    /**
     * Pedido de la socia en un pedido con los dos productores, entregado ese día.
     *
     * @param array{naranjas?: string, aceite?: string} $quantities
     */
    private function order(string $delivery, array $quantities): ConsumerGroupOrder
    {
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_CONFIRMED)->setConfirmed(true);
        $round->setTitle('Ronda ' . $delivery)
            ->setOrdersCloseAt(new \DateTime($delivery . ' -3 days'))
            ->setDeliveryDate(new \DateTime($delivery));
        foreach ($this->producers as $producer) {
            $round->addProducer($producer);
        }
        $items = [
            'naranjas' => new ConsumerGroupRoundItem($round, $this->naranjas, '2.50'),
            'aceite' => new ConsumerGroupRoundItem($round, $this->aceite, '8.00'),
        ];
        foreach ($items as $item) {
            $round->addItem($item);
        }
        $this->em()->persist($round);

        $order = new ConsumerGroupOrder($round, $this->partner);
        foreach ($quantities as $key => $quantity) {
            $order->addLine(new ConsumerGroupOrderLine($order, $items[$key], $quantity));
        }
        $this->em()->persist($order);

        return $order;
    }
}
