<?php

namespace App\Tests\Repository;

use App\Entity\ConsumerGroupRound;
use App\Entity\Producer;
use App\Repository\ConsumerGroupRoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Las consultas de pedidos por productor contra la base, con un pedido que reúne a dos:
 * filtrar por uno devuelve el pedido con TODOS sus productores cargados (no solo el
 * filtrado) y cada productor lo ve en el suyo.
 */
class ConsumerGroupRoundProducersQueriesTest extends KernelTestCase
{
    private Producer $naranjero;

    private Producer $almazara;

    private ConsumerGroupRound $round;

    protected function setUp(): void
    {
        $em = $this->em();
        $this->naranjero = (new Producer())->setName('Naranjero ' . uniqid());
        $this->almazara = (new Producer())->setName('Almazara ' . uniqid());
        $this->round = (new ConsumerGroupRound())->setTitle('Mixto repo')
            ->setOrdersCloseAt(new \DateTime('2097-05-01'))->setDeliveryDate(new \DateTime('2097-05-05'));
        $this->round->addProducer($this->naranjero)->addProducer($this->almazara);
        foreach ([$this->naranjero, $this->almazara, $this->round] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $em->createQuery('DELETE FROM App\Entity\ConsumerGroupRound r WHERE :p MEMBER OF r.producers')->execute(['p' => $this->naranjero->getId()]);
        $em->createQuery('DELETE FROM App\Entity\Producer p WHERE p.id IN (:ids)')->execute(['ids' => [$this->naranjero->getId(), $this->almazara->getId()]]);
        parent::tearDown();
    }

    public function testFiltrarPorUnProductorDevuelveElPedidoConTodosSusProductores(): void
    {
        $found = $this->repo()->findFilteredForManagement(['producer' => $this->naranjero->getId(), 'status' => null, 'from' => null, 'to' => null]);

        self::assertCount(1, $found);
        self::assertSame($this->round->getId(), $found[0]->getId());
        self::assertCount(2, $found[0]->getProducers(), 'El filtro no recorta la colección cargada');
    }

    public function testCadaProductorVeElPedidoEnElSuyo(): void
    {
        foreach ([$this->naranjero, $this->almazara] as $producer) {
            $found = $this->repo()->findAllForProducer($producer);

            self::assertCount(1, $found);
            self::assertCount(2, $found[0]->getProducers());
        }
    }

    public function testLosDosProductoresSalenEnElFiltroDeProductoresConPedidos(): void
    {
        $ids = array_map(static fn (Producer $p): ?int => $p->getId(), $this->repo()->findProducersWithRounds());

        self::assertContains($this->naranjero->getId(), $ids);
        self::assertContains($this->almazara->getId(), $ids);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function repo(): ConsumerGroupRoundRepository
    {
        return static::getContainer()->get(ConsumerGroupRoundRepository::class);
    }
}
