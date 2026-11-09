<?php

namespace App\Tests\Repository;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupRound;
use App\Entity\Partner;
use App\Entity\PartnerPaymentMethod;
use App\Entity\Producer;
use App\Entity\Remittance;
use App\Repository\ConsumerGroupOrderRepository;
use App\Service\Accounting\Remittance\RemittanceFromConsumerGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Las consultas del cobro del grupo de consumo contra la base: qué pedidos van en la
 * remesa de un mes y cuáles se entregan («pagado o va en el recibo»). Rondas en 2097
 * y un productor propio para no cruzarse con nada.
 */
class ConsumerGroupOrderPaymentQueriesTest extends KernelTestCase
{
    private Producer $producer;

    /** @var list<Partner> */
    private array $partners = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $em->createQuery('DELETE FROM App\Entity\ConsumerGroupOrder o WHERE o.round IN (SELECT r.id FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p)')->execute(['p' => $this->producer->getId()]);
        $em->createQuery('DELETE FROM App\Entity\ConsumerGroupRound r WHERE r.producer = :p')->execute(['p' => $this->producer->getId()]);
        $em->createQuery('DELETE FROM App\Entity\Remittance r WHERE r.kind = :k AND r.period >= :y')->execute(['k' => Remittance::KIND_CONSUMER_GROUP, 'y' => '2097-01-01']);
        $em->createQuery('DELETE FROM App\Entity\Producer p WHERE p.id = :p')->execute(['p' => $this->producer->getId()]);
        $em->createQuery('DELETE FROM App\Entity\Partner p WHERE p.id IN (:ids)')->execute(['ids' => array_map(static fn (Partner $p): ?int => $p->getId(), $this->partners)]);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        $this->producer = (new Producer())->setName('Huerta Cobro ' . uniqid());
        $this->em()->persist($this->producer);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function repo(): ConsumerGroupOrderRepository
    {
        return static::getContainer()->get(ConsumerGroupOrderRepository::class);
    }

    /** Una socia; con mandato completo si se dice (referencia única: la base no deja repetirla). */
    private function partner(string $name, bool $mandate, PartnerPaymentMethod $method = PartnerPaymentMethod::Receipt): Partner
    {
        $partner = (new Partner())->setname($name)->setSurname('Cobro ' . uniqid())->setPaymentMethod($method);
        if ($mandate) {
            $partner->setIban('ES91 2100 0418 4502 0005 1332')
                ->setMandateReference('CGTEST ' . uniqid())
                ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        }
        $this->em()->persist($partner);
        $this->partners[] = $partner;

        return $partner;
    }

    private function round(string $delivery, bool $confirmed = true, int $status = ConsumerGroupRound::STATUS_CONFIRMED): ConsumerGroupRound
    {
        $round = (new ConsumerGroupRound())->setStatus($status);
        $round->setTitle('Ronda cobro ' . $delivery)->setProducer($this->producer)
            ->setOrdersCloseAt(new \DateTime($delivery . ' -3 days'))
            ->setDeliveryDate(new \DateTime($delivery));
        $round->setConfirmed($confirmed);
        $this->em()->persist($round);

        return $round;
    }

    private function order(ConsumerGroupRound $round, Partner $partner): ConsumerGroupOrder
    {
        $order = new ConsumerGroupOrder($round, $partner);
        $this->em()->persist($order);

        return $order;
    }

    public function testLaRemesaDelMesLlevaSoloLoQueSePuedeCobrarPorRecibo(): void
    {
        $ana = $this->partner('Ana', true);
        $bea = $this->partner('Bea', false);
        $cris = $this->partner('Cris', true, PartnerPaymentMethod::Transfer);
        $dani = $this->partner('Dani', true);
        $march = $this->round('2097-03-10');
        $anaMarch = $this->order($march, $ana);
        $anaFebruary = $this->order($this->round('2097-02-10'), $ana);
        $this->order($this->round('2097-03-12', false, ConsumerGroupRound::STATUS_OPEN), $ana);
        $this->order($this->round('2097-03-15', true, ConsumerGroupRound::STATUS_CANCELLED), $ana);
        $this->order($this->round('2097-04-05'), $ana);
        $this->order($march, $bea);
        $this->order($march, $cris);
        $this->order($march, $dani)->markPaid(ConsumerGroupPaymentMethod::Cash);
        $this->em()->flush();

        $found = $this->repo()->findToChargeByReceiptIn(new \DateTimeImmutable('2097-03-01'));

        // Febrero entra: no se cobró en su mes y ya se entregó contando con el recibo.
        $this->assertSame([$anaFebruary->getId(), $anaMarch->getId()], array_map(static fn (ConsumerGroupOrder $o): ?int => $o->getId(), $found));
    }

    public function testUnPedidoQueYaVaEnUnReciboNoVuelveAEntrarYAlEnviarQuedaCobrado(): void
    {
        $ana = $this->partner('Ana', true);
        $march = $this->round('2097-03-10');
        $anaMarch = $this->order($march, $ana);
        $this->em()->flush();

        $remittance = (new RemittanceFromConsumerGroup())->build(new \DateTimeImmutable('2097-03-01'), $this->repo()->findToChargeByReceiptIn(new \DateTimeImmutable('2097-03-01')), null);
        $this->em()->persist($remittance);
        // Una socia que se apunta después del borrador no va en él.
        $late = $this->order($march, $this->partner('Eva', true));
        $this->em()->flush();

        $this->assertSame([$late->getId()], array_map(static fn (ConsumerGroupOrder $o): ?int => $o->getId(), $this->repo()->findToChargeByReceiptIn(new \DateTimeImmutable('2097-03-01'))));
        $this->assertSame([$anaMarch->getId()], array_map(static fn (ConsumerGroupOrder $o): ?int => $o->getId(), $this->repo()->findInRemittance($remittance)));
    }

    public function testTirarElBorradorDejaElPedidoParaLaSiguiente(): void
    {
        $ana = $this->partner('Ana', true);
        $anaMarch = $this->order($this->round('2097-03-10'), $ana);
        $this->em()->flush();
        $remittance = (new RemittanceFromConsumerGroup())->build(new \DateTimeImmutable('2097-03-01'), [$anaMarch], null);
        $this->em()->persist($remittance);
        $this->em()->flush();

        $this->em()->remove($remittance);
        $this->em()->flush();
        $this->em()->clear();

        $this->assertNull($this->repo()->find($anaMarch->getId())->getRemittanceLine(), 'La base suelta el enlace (ON DELETE SET NULL).');
        $this->assertCount(1, $this->repo()->findToChargeByReceiptIn(new \DateTimeImmutable('2097-03-01')));
    }

    public function testSeEntregaLoPagadoYLoQueVaEnElRecibo(): void
    {
        $ana = $this->partner('Ana', true);
        $bea = $this->partner('Bea', false);
        $march = $this->round('2097-03-10');
        $this->order($march, $ana);
        $beaOrder = $this->order($march, $bea);
        $this->em()->flush();
        $from = new \DateTime('2097-01-01');

        $this->assertCount(1, $this->repo()->findDeliverableUpcomingForPartner($ana, $from), 'Con recibo: se le entrega aunque no haya pagado aún.');
        $this->assertCount(0, $this->repo()->findDeliverableUpcomingForPartner($bea, $from), 'Sin recibo y sin pagar: no.');

        $beaOrder->markPaid(ConsumerGroupPaymentMethod::Transfer);
        $this->em()->flush();

        $this->assertCount(1, $this->repo()->findDeliverableUpcomingForPartner($bea, $from), 'Pagado por transferencia: sí.');
    }
}
