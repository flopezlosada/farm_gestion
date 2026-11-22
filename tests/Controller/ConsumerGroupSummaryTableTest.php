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
 * La tabla «Pedido al productor» tiene que poder leerse sin conocer el código:
 * dice cuántas socias han pedido, en qué unidad van las cantidades y qué es cada
 * columna.
 */
class ConsumerGroupSummaryTableTest extends AbstractAuthenticatedTest
{
    public function testLaTablaNombraLasColumnasYLaUnidad(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $round = $this->prepararRondaConUnPedido('4');

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $round->getId());

        $card = $crawler->filter('section.prt-card')->reduce(
            static fn ($node): bool => str_contains($node->filter('.prt-card__title')->text(''), 'Pedido al productor'),
        );
        self::assertCount(1, $card);
        $text = preg_replace('/\s+/', ' ', $card->text());
        self::assertStringContainsString('1 socia ha pedido', $text);
        foreach (['Nº de «kg» de Naranjas', 'De las socias', 'Para el local', 'Total a pedir', 'Importe', 'Para el productor', 'Gestión'] as $label) {
            self::assertStringContainsString($label, $text);
        }
        self::assertStringContainsString('se pide por kg', $text);
        self::assertStringContainsString('2,50 € / kg', $text);
    }

    public function testLasCantidadesEnterasSeMuestranSinDecimales(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $round = $this->prepararRondaConUnPedido('4');

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $round->getId());

        $row = $crawler->filter('section.prt-card table.prl-table tbody tr')->first();
        $cells = $row->filter('td')->each(static fn ($td): string => trim(preg_replace('/\s+/', ' ', $td->text())));
        self::assertSame('4', $cells[2], 'De las socias: 4 kg, no «4,00».');
        self::assertSame('4', $cells[4], 'Total a pedir: 4 kg, no «4,00».');
        self::assertSame(['10,00 €', '0,00 €', '10,00 €'], array_slice($cells, 6, 3), 'Sin precio de productor, todo el importe es del productor y la gestión es cero.');
    }

    /**
     * Una ronda abierta con un producto en kg y el pedido de una socia.
     */
    private function prepararRondaConUnPedido(string $quantity): ConsumerGroupRound
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $producer = (new Producer())->setName('Huerta Test ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $producer->addProduct($naranjas);

        $round = new ConsumerGroupRound();
        $round->setTitle('Ronda con resumen')->addProducer($producer)->setOrdersCloseAt(new \DateTime('+5 days'))->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);

        $partner = (new Partner())->setname('Socia')->setSurname('Test ' . uniqid());
        $order = new ConsumerGroupOrder($round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, $quantity));

        foreach ([$producer, $naranjas, $round, $item, $partner, $order] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $round;
    }
}
