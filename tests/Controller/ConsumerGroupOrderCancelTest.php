<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Cancelar el pedido de una socia desde el menú de su fila: lo vacía igual que
 * cuando ella deja las cantidades a cero, y sólo si el pago está pendiente.
 */
class ConsumerGroupOrderCancelTest extends AbstractAuthenticatedTest
{
    public function testCancelarUnPedidoPendienteLoVaciaYLoDejaEnLaBitacora(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $order = $this->prepararPedido();

        $client->request('POST', $this->cancelUrl($order), ['_token' => $this->cancelToken($client, $order)]);

        self::assertResponseRedirects('/gestion/consumer-group/' . $order->getRound()->getId());
        $reloaded = $this->reload($order);
        self::assertTrue($reloaded->isEmpty(), 'Cancelar deja el pedido sin líneas con cantidad.');
        $kinds = array_map(
            static fn (ConsumerGroupEventLog $e): string => $e->getKind(),
            self::getContainer()->get('doctrine')->getManager()->getRepository(ConsumerGroupEventLog::class)->findBy(['round' => $reloaded->getRound()]),
        );
        self::assertContains(ConsumerGroupEventLog::KIND_ORDER_EMPTIED, $kinds);
    }

    public function testUnPedidoPagadoNoSeCancela(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $order = $this->prepararPedido(pagado: true);

        $client->request('POST', $this->cancelUrl($order), ['_token' => $this->cancelToken($client, $order)]);

        self::assertFalse($this->reload($order)->isEmpty(), 'Un pedido cobrado tiene dinero detrás: no se vacía.');
    }

    public function testSinTokenValidoNoSeCancela(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $order = $this->prepararPedido();

        $client->request('POST', $this->cancelUrl($order), ['_token' => 'falso']);

        self::assertFalse($this->reload($order)->isEmpty());
    }

    public function testElMenuOfreceCancelarSoloConElPagoPendiente(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $pendiente = $this->prepararPedido();
        $pagado = $this->prepararPedido(pagado: true);

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $pendiente->getRound()->getId());
        self::assertCount(1, $crawler->filter('form[action$="/orders/' . $pendiente->getId() . '/cancel"]'));

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $pagado->getRound()->getId());
        self::assertCount(0, $crawler->filter('form[action$="/orders/' . $pagado->getId() . '/cancel"]'));
    }

    /**
     * Una ronda abierta con un pedido de 4 kg de una socia, pagado o no.
     */
    private function prepararPedido(bool $pagado = false): ConsumerGroupOrder
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $producer = (new Producer())->setName('Huerta Test ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $producer->addProduct($naranjas);

        $round = new ConsumerGroupRound();
        $round->setTitle('Ronda con pedidos')->addProducer($producer)->setOrdersCloseAt(new \DateTime('+5 days'))->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);

        $partner = (new Partner())->setname('Socia')->setSurname('Test ' . uniqid());
        $order = new ConsumerGroupOrder($round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, '4'));
        if ($pagado) {
            $order->markPaid(ConsumerGroupPaymentMethod::Cash);
        }

        foreach ([$producer, $naranjas, $round, $item, $partner, $order] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $order;
    }

    private function cancelUrl(ConsumerGroupOrder $order): string
    {
        return '/gestion/consumer-group/orders/' . $order->getId() . '/cancel';
    }

    /**
     * El token sale del formulario ya pintado: el gestor de CSRF exige una sesión
     * abierta, que sólo existe tras la primera petición.
     */
    private function cancelToken(KernelBrowser $client, ConsumerGroupOrder $order): string
    {
        $crawler = $client->request('GET', '/gestion/consumer-group/' . $order->getRound()->getId());
        $form = $crawler->filter('form[action$="/orders/' . $order->getId() . '/cancel"] input[name="_token"]');

        return $form->count() > 0 ? $form->attr('value') : 'sin-formulario';
    }

    /**
     * Relee el pedido de la base, no de la identity map: lo que importa es lo que
     * quedó guardado.
     */
    private function reload(ConsumerGroupOrder $order): ConsumerGroupOrder
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(ConsumerGroupOrder::class)->find($order->getId());
    }
}
