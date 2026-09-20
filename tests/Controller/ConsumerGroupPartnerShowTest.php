<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupUnit;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Service\AppSettings;

/**
 * Ficha de la socia DENTRO del grupo de consumo (distinta de su ficha general
 * de socia): sus cifras y el detalle de sus pedidos en el módulo.
 */
class ConsumerGroupPartnerShowTest extends AbstractAuthenticatedTest
{
    public function testFichaMuestraEstadisticasYPedidos(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [, $partner] = $this->prepararRondaConfirmadaConPedido();

        $crawler = $client->request('GET', '/gestion/consumer-group/partners/' . $partner->getId());

        self::assertResponseIsSuccessful();
        // 4 kg a 2,50 €/kg confirmados en 1 ronda: 1 ronda, 10,00 € gastados.
        $kpis = $crawler->filter('.cg-kpi__v')->each(static fn ($node) => trim($node->text()));
        self::assertSame(['1', '10,00 €'], $kpis);
        self::assertSelectorTextContains('.prl-table', 'Ronda de test');
        self::assertSelectorTextContains('.prl-table', '10,00 €');
    }

    /**
     * @return array{0: ConsumerGroupRound, 1: Partner}
     */
    private function prepararRondaConfirmadaConPedido(): array
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $producer = (new Producer())->setName('Huerta Test ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit((new ConsumerGroupUnit())->setName('kg'));
        $producer->addProduct($naranjas);

        $round = new ConsumerGroupRound();
        $round->setTitle('Ronda de test')->setProducer($producer)->setOrdersCloseAt(new \DateTime('tomorrow'));
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);
        $round->setConfirmed(true);

        $partner = (new Partner())->setname('Socia')->setSurname('Test ' . uniqid());
        $order = new ConsumerGroupOrder($round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, '4'));

        $em->persist($naranjas->getUnit());
        $em->persist($producer);
        $em->persist($naranjas);
        $em->persist($round);
        $em->persist($item);
        $em->persist($partner);
        $em->persist($order);
        $em->flush();

        return [$round, $partner];
    }
}
