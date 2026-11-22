<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Service\AppSettings;

/**
 * El CSV al productor de un pedido con varios lleva solo lo de ese productor, a su
 * precio; uno que no participa en el pedido no se puede pedir.
 */
class ConsumerGroupExportByProducerTest extends AbstractAuthenticatedTest
{
    public function testCadaProductorSoloRecibeSusProductosASuPrecio(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjero] = $this->pedidoMixto();

        $client->request('GET', sprintf('/gestion/consumer-group/%d/export?producer=%d', $round->getId(), $naranjero->getId()));
        $csv = $client->getInternalResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Naranjas', $csv);
        self::assertStringNotContainsString('Aceite', $csv);
        self::assertStringContainsString('"2,00"', $csv, 'El precio del productor, sin el incremento de gestión');
        self::assertStringNotContainsString('2,50', $csv);
    }

    public function testUnProductorQueNoParticipaDaNotFound(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round] = $this->pedidoMixto();
        $ajeno = (new Producer())->setName('Ajeno ' . uniqid());
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($ajeno);
        $em->flush();

        $client->request('GET', sprintf('/gestion/consumer-group/%d/export?producer=%d', $round->getId(), $ajeno->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{0: ConsumerGroupRound, 1: Producer}
     */
    private function pedidoMixto(): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $naranjero = (new Producer())->setName('Naranjero ' . uniqid());
        $almazara = (new Producer())->setName('Almazara ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $aceite = (new ConsumerGroupProduct())->setName('Aceite')->setUnit($this->unidadGlobal('kg'));
        $naranjero->addProduct($naranjas);
        $almazara->addProduct($aceite);

        $round = (new ConsumerGroupRound())->setTitle('Mixto export')->setOrdersCloseAt(new \DateTime('+5 days'))
            ->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_NONE);
        $round->addProducer($naranjero)->addProducer($almazara);
        $itemNaranjas = (new ConsumerGroupRoundItem($round, $naranjas, '2.50'))->setProducerPrice('2.00');
        $itemAceite = new ConsumerGroupRoundItem($round, $aceite, '9.00');
        $round->addItem($itemNaranjas);
        $round->addItem($itemAceite);
        $partner = (new Partner())->setname('Ana')->setSurname('Export ' . uniqid());
        $order = new ConsumerGroupOrder($round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $itemNaranjas, '2'));
        $order->addLine(new ConsumerGroupOrderLine($order, $itemAceite, '1'));
        foreach ([$partner, $naranjero, $almazara, $naranjas, $aceite, $round, $itemNaranjas, $itemAceite, $order] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$round, $naranjero];
    }
}
