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
use Symfony\Component\DomCrawler\Crawler;

/**
 * La tabla «Pedido al productor» tiene que poder leerse sin conocer el código:
 * dice cuántas socias han pedido, en qué unidad van las cantidades, qué es cada
 * columna y cuánto del importe es del productor y cuánto es gestión.
 */
class ConsumerGroupSummaryTableTest extends AbstractAuthenticatedTest
{
    public function testLaTablaNombraLasColumnasYLaUnidad(): void
    {
        $client = $this->createAuthenticatedClient();
        $round = $this->prepararRonda([['Naranjas', 'kg', '2.50', null, '4']]);

        $card = $this->tarjeta($client->request('GET', '/gestion/consumer-group/' . $round->getId()));

        $text = preg_replace('/\s+/', ' ', $card->text());
        self::assertStringContainsString('1 socia ha pedido', $text);
        self::assertStringContainsString('Nº de «kg» de Naranjas', $text);
        self::assertStringContainsString('se pide por kg', $text);
        self::assertStringContainsString('2,50 € / kg', $text);
        $headers = $card->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text()));
        foreach (['De las socias', 'Para el local', 'Total a pedir', 'Para el productor', 'Gestión'] as $label) {
            self::assertContains($label, $headers);
        }
    }

    public function testLasCantidadesEnterasVanSinDecimalesYLasDemasConDos(): void
    {
        $client = $this->createAuthenticatedClient();
        $round = $this->prepararRonda([['Naranjas', 'kg', '2.50', null, '4'], ['Limones', 'kg', '3.00', null, '2.25']]);

        $card = $this->tarjeta($client->request('GET', '/gestion/consumer-group/' . $round->getId()));

        self::assertSame(['4', '2,25'], $this->columna($card, 'De las socias'), 'Entero sin ceros; fraccionario sin perder cuartos.');
        self::assertSame(['4', '2,25'], $this->columna($card, 'Total a pedir'));
    }

    public function testElImporteSeDesglosaEnProductorYGestion(): void
    {
        $client = $this->createAuthenticatedClient();
        // Precio final 2,50 = 2,00 del productor + 0,50 de gestión; 4 kg.
        $round = $this->prepararRonda([['Naranjas', 'kg', '2.50', '2.00', '4']]);

        $card = $this->tarjeta($client->request('GET', '/gestion/consumer-group/' . $round->getId()));

        self::assertSame(['8,00 €'], $this->columna($card, 'Para el productor'));
        self::assertSame(['2,00 €'], $this->columna($card, 'Gestión'));
        self::assertSame(['10,00 €'], $this->columna($card, 'Total'));
    }

    public function testSinPrecioDeProductorTodoElImporteEsDelProductor(): void
    {
        $client = $this->createAuthenticatedClient();
        $round = $this->prepararRonda([['Naranjas', 'kg', '2.50', null, '4']]);

        $card = $this->tarjeta($client->request('GET', '/gestion/consumer-group/' . $round->getId()));

        self::assertSame(['10,00 €'], $this->columna($card, 'Para el productor'));
        self::assertSame(['0,00 €'], $this->columna($card, 'Gestión'));
    }

    public function testLaCabeceraDeCantidadesSegunLasUnidades(): void
    {
        $client = $this->createAuthenticatedClient();
        $mismaUnidad = $this->prepararRonda([['Naranjas', 'kg', '2.50', null, '4'], ['Limones', 'kg', '3.00', null, '1']]);
        $unidadesMezcladas = $this->prepararRonda([['Naranjas', 'kg', '2.50', null, '4'], ['Huevos', 'docena', '3.00', null, '1']]);

        $card = $this->tarjeta($client->request('GET', '/gestion/consumer-group/' . $mismaUnidad->getId()));
        $title = $card->filter('thead th.cg-agg__group')->first()->text();
        self::assertStringContainsString('Nº de «kg»', $title);
        self::assertStringNotContainsString('de Naranjas', $title);

        $card = $this->tarjeta($client->request('GET', '/gestion/consumer-group/' . $unidadesMezcladas->getId()));
        self::assertStringContainsString('Nº de unidades de cada producto', $card->filter('thead th.cg-agg__group')->first()->text());
    }

    /**
     * Tarjeta «Pedido al productor» de la página, para no confundir su tabla con
     * la de «Pedidos por socia», que está debajo.
     */
    private function tarjeta(Crawler $page): Crawler
    {
        self::assertResponseIsSuccessful();
        $card = $page->filter('section.prt-card')->reduce(
            static fn (Crawler $node): bool => str_contains($node->filter('.prt-card__title')->text(''), 'Pedido al productor'),
        );
        self::assertCount(1, $card);

        return $card;
    }

    /**
     * Texto de la celda con esa etiqueta en cada fila de producto. El pie con los
     * totales del pedido queda fuera: su primera celda ocupa cinco columnas.
     *
     * @return list<string>
     */
    private function columna(Crawler $card, string $label): array
    {
        $rows = $card->filter('table.prl-table tbody tr')->reduce(
            static fn (Crawler $tr): bool => $tr->filter('td')->first()->attr('colspan') === null,
        );

        return $rows->each(static fn (Crawler $tr): string => trim(preg_replace('/\s+/', ' ', $tr->filter('td[data-label="' . $label . '"]')->text())));
    }

    /**
     * Una ronda abierta con el pedido de una socia y un producto por cada línea de `$lines`.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: ?string, 4: string}> $lines nombre, unidad, precio final, precio del productor y cantidad pedida
     */
    private function prepararRonda(array $lines): ConsumerGroupRound
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $em = self::getContainer()->get('doctrine')->getManager();

        $producer = (new Producer())->setName('Huerta Test ' . uniqid());
        $round = new ConsumerGroupRound();
        $round->setTitle('Ronda con resumen')->addProducer($producer)->setOrdersCloseAt(new \DateTime('+5 days'))->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $partner = (new Partner())->setname('Socia')->setSurname('Test ' . uniqid());
        $order = new ConsumerGroupOrder($round, $partner);

        $entities = [$producer, $round, $partner, $order];
        foreach ($lines as [$name, $unit, $price, $producerPrice, $quantity]) {
            $product = (new ConsumerGroupProduct())->setName($name)->setUnit($this->unidadGlobal($unit));
            $producer->addProduct($product);
            $item = (new ConsumerGroupRoundItem($round, $product, $price))->setProducerPrice($producerPrice);
            $round->addItem($item);
            $order->addLine(new ConsumerGroupOrderLine($order, $item, $quantity));
            array_push($entities, $product, $item);
        }

        foreach ($entities as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $round;
    }
}
