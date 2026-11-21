<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\MarkupType;
use App\Entity\Producer;
use App\Service\AppSettings;

/**
 * El precio de los productos del pedido se desglosa en precio del productor + incremento
 * de gestión: el del pedido lo heredan todos y los que tienen el suyo quedan pendientes
 * de revisar —y el pedido sin abrir— hasta que la comisión los valida.
 */
class ConsumerGroupItemsMarkupTest extends AbstractAuthenticatedTest
{
    public function testCambiarElIncrementoDelPedidoReprecioaYDejaPendienteElPropio(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas, $aceite] = $this->prepararBorrador();

        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', $this->payload($client, $round, $naranjas, $aceite));

        self::assertResponseRedirects('/gestion/consumer-group/' . $round->getId());
        [$precios, $pendientes] = $this->estado($round);
        self::assertSame('2.20', $precios['Naranjas'], 'Hereda el 10 % del pedido');
        self::assertSame('8.50', $precios['Aceite'], 'Con incremento propio no se recalcula solo');
        self::assertSame(['Aceite'], $pendientes);

        $client->request('GET', '/gestion/consumer-group/' . $round->getId() . '/announce');
        self::assertResponseRedirects('/gestion/consumer-group/' . $round->getId());
        self::assertTrue($this->recargar($round)->isDraft(), 'Con un incremento pendiente el pedido no se abre');
    }

    public function testRevisarElIncrementoPropioLoQuitaDePendientes(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas, $aceite] = $this->prepararBorrador();
        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', $this->payload($client, $round, $naranjas, $aceite));

        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', $this->payload($client, $round, $naranjas, $aceite, [
            'reviewed' => [$aceite->getId() => '1'],
        ]));

        [, $pendientes] = $this->estado($round);
        self::assertSame([], $pendientes);
        self::assertTrue($this->recargar($round)->isDraft(), 'Revisar no abre el pedido: abrir sigue siendo a mano');
    }

    public function testGuardarSinCambiarElIncrementoNoMarcaPendientes(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas, $aceite] = $this->prepararBorrador();
        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', $this->payload($client, $round, $naranjas, $aceite, ['reviewed' => [$aceite->getId() => '1']]));

        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', $this->payload($client, $round, $naranjas, $aceite));

        [, $pendientes] = $this->estado($round);
        self::assertSame([], $pendientes);
    }

    public function testUnPedidoEntregadoSinDesglosarSePuedeDesglosarSinCambiarElPrecioFinal(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas] = $this->prepararEntregadoSinDesglosar();

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $round->getId() . '/items');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', [
            '_token'         => $crawler->filter('input[name="_token"]')->attr('value'),
            // Un intento de quitar el producto y de cambiar su precio: no cuenta, el pedido está entregado.
            'include'        => [],
            'price'          => [$naranjas->getId() => '99.00'],
            'producer_price' => [$naranjas->getId() => '20.00'],
        ]);

        self::assertResponseRedirects('/gestion/consumer-group/' . $round->getId());
        $items = $this->recargar($round)->getItems();
        self::assertCount(1, $items, 'No se quita nada');
        self::assertSame('26.00', $items->first()->getPrice(), 'El precio final no cambia');
        self::assertSame('20.00', $items->first()->getProducerPrice());
        self::assertSame('6.00', $items->first()->getMarkupValue(), 'El incremento es la diferencia');
    }

    public function testUnFormularioSinElCampoDelIncrementoDelPedidoNoLoQuita(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas, $aceite] = $this->prepararBorrador();
        $payload = $this->payload($client, $round, $naranjas, $aceite);
        unset($payload['round_markup_type'], $payload['round_markup_value']);

        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', $payload);

        self::assertSame(MarkupType::Percent, $this->recargar($round)->getMarkupType(), 'Campo ausente = sin cambios');
        self::assertSame('5.00', $this->recargar($round)->getMarkupValue());
    }

    public function testUnPrecioDeProductorAbsurdoNoRompeElGuardado(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas, $aceite] = $this->prepararBorrador();
        $payload = $this->payload($client, $round, $naranjas, $aceite);
        $payload['producer_price'][$naranjas->getId()] = '1e9';
        $payload['markup_value'][$aceite->getId()] = [['x']];

        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', $payload);

        self::assertResponseRedirects('/gestion/consumer-group/' . $round->getId() . '/items');
        [$precios] = $this->estado($round);
        self::assertSame('2.10', $precios['Naranjas'], 'No se guarda nada');
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload($client, ConsumerGroupRound $round, ConsumerGroupProduct $naranjas, ConsumerGroupProduct $aceite, array $extra = []): array
    {
        $crawler = $client->request('GET', '/gestion/consumer-group/' . $round->getId() . '/items');

        return $extra + [
            '_token'              => $crawler->filter('input[name="_token"]')->attr('value'),
            'include'             => [$naranjas->getId() => '1', $aceite->getId() => '1'],
            'round_markup_type'   => 'percent',
            'round_markup_value'  => '10',
            'producer_price'      => [$naranjas->getId() => '2.00', $aceite->getId() => '8.00'],
            'markup_type'         => [$naranjas->getId() => '', $aceite->getId() => 'amount'],
            'markup_value'        => [$naranjas->getId() => '', $aceite->getId() => '0.50'],
        ];
    }

    /**
     * Borrador de un productor gestionado, con un producto que hereda el incremento y
     * otro con el suyo (8,00 + 0,50 €).
     *
     * @return array{0: ConsumerGroupRound, 1: ConsumerGroupProduct, 2: ConsumerGroupProduct}
     */
    private function prepararBorrador(): array
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $producer = (new Producer())->setName('Huerta Test ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $aceite = (new ConsumerGroupProduct())->setName('Aceite')->setUnit($this->unidadGlobal('L'));
        $producer->addProduct($naranjas);
        $producer->addProduct($aceite);

        $round = new ConsumerGroupRound();
        $round->setTitle('Ronda con incremento')->setProducer($producer)->setOrdersCloseAt(new \DateTime('+5 days'))->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_NONE);
        $round->setMarkup(MarkupType::Percent, '5.00');
        $itemNaranjas = (new ConsumerGroupRoundItem($round, $naranjas, '2.10'))->setProducerPrice('2.00');
        $itemAceite = (new ConsumerGroupRoundItem($round, $aceite, '8.50'))->setProducerPrice('8.00')->setOwnMarkup(MarkupType::Amount, '0.50');
        $round->addItem($itemNaranjas);
        $round->addItem($itemAceite);

        foreach ([$producer, $naranjas, $aceite, $round, $itemNaranjas, $itemAceite] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$round, $naranjas, $aceite];
    }

    /**
     * Pedido entregado de un productor gestionado, anterior al desglose: precio final
     * 26,00 sin precio de productor.
     *
     * @return array{0: ConsumerGroupRound, 1: ConsumerGroupProduct}
     */
    private function prepararEntregadoSinDesglosar(): array
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $producer = (new Producer())->setName('Huerta Test ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $producer->addProduct($naranjas);
        $round = new ConsumerGroupRound();
        $round->setTitle('Pedido entregado')->setProducer($producer)->setOrdersCloseAt(new \DateTime('-10 days'))->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_NONE);
        $round->setStatus(ConsumerGroupRound::STATUS_DELIVERED);
        $item = new ConsumerGroupRoundItem($round, $naranjas, '26.00');
        $round->addItem($item);
        foreach ([$producer, $naranjas, $round, $item] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$round, $naranjas];
    }

    private function recargar(ConsumerGroupRound $round): ConsumerGroupRound
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(ConsumerGroupRound::class)->find($round->getId());
    }

    /**
     * Precio final por producto y nombres de los pendientes, leídos de la base.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function estado(ConsumerGroupRound $round): array
    {
        $precios = [];
        $pendientes = [];
        foreach ($this->recargar($round)->getItems() as $item) {
            $precios[$item->getName()] = $item->getPrice();
            if ($item->isMarkupPendingReview()) {
                $pendientes[] = $item->getName();
            }
        }

        return [$precios, $pendientes];
    }
}
