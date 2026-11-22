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
 *
 * Los tests de las guardas de negocio piden el token con el pedido aún pendiente
 * y cambian su estado DESPUÉS: así el POST llega con un token válido y lo que lo
 * rechaza es la guarda, no el CSRF.
 */
class ConsumerGroupOrderCancelTest extends AbstractAuthenticatedTest
{
    public function testCancelarUnPedidoPendienteLoVaciaYLoDejaEnLaBitacora(): void
    {
        $client = $this->createAuthenticatedClient();
        $order = $this->prepararPedido();

        $client->request('POST', $this->cancelUrl($order), ['_token' => $this->cancelToken($client, $order)]);

        self::assertResponseRedirects('/gestion/consumer-group/' . $order->getRound()->getId());
        self::assertTrue($this->reload($order)->isEmpty(), 'Cancelar deja el pedido sin líneas con cantidad.');
        self::assertSame(1, $this->countEmptiedEvents($order));
    }

    public function testUnPedidoPagadoNoSeCancela(): void
    {
        $client = $this->createAuthenticatedClient();
        $order = $this->prepararPedido();
        $token = $this->cancelToken($client, $order);
        $this->modificar($order, static fn (ConsumerGroupOrder $o) => $o->markPaid(ConsumerGroupPaymentMethod::Cash));

        $client->request('POST', $this->cancelUrl($order), ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertFalse($this->reload($order)->isEmpty(), 'Un pedido cobrado tiene dinero detrás: no se vacía.');
        self::assertSame(0, $this->countEmptiedEvents($order));
    }

    public function testEnUnaRondaQueYaNoAdmiteCambiosNoSeCancela(): void
    {
        $client = $this->createAuthenticatedClient();
        $order = $this->prepararPedido();
        $token = $this->cancelToken($client, $order);
        $this->modificar($order, static fn (ConsumerGroupOrder $o) => $o->getRound()->setStatus(ConsumerGroupRound::STATUS_CANCELLED));

        $client->request('POST', $this->cancelUrl($order), ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertFalse($this->reload($order)->isEmpty());
        self::assertSame(0, $this->countEmptiedEvents($order));
    }

    public function testCancelarDosVecesNoRepiteElEventoDeLaBitacora(): void
    {
        $client = $this->createAuthenticatedClient();
        $order = $this->prepararPedido();
        $token = $this->cancelToken($client, $order);

        $client->request('POST', $this->cancelUrl($order), ['_token' => $token]);
        $client->request('POST', $this->cancelUrl($order), ['_token' => $token]);

        self::assertSame(1, $this->countEmptiedEvents($order));
    }

    public function testSinTokenValidoNoSeCancela(): void
    {
        $client = $this->createAuthenticatedClient();
        $order = $this->prepararPedido();

        $client->request('POST', $this->cancelUrl($order), ['_token' => 'falso']);

        self::assertFalse($this->reload($order)->isEmpty());
        self::assertSame(0, $this->countEmptiedEvents($order));
    }

    public function testQuienSoloPuedeLeerNoVeLaOpcionNiPuedeCancelar(): void
    {
        $client = static::createClient();
        $order = $this->prepararPedido();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_GRUPO_CONSUMO']));

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $order->getRound()->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/orders/' . $order->getId() . '/cancel"]'), 'Sin permiso de edición no se ofrece cancelar.');

        $client->request('POST', $this->cancelUrl($order), ['_token' => 'da-igual']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($order)->isEmpty());
    }

    public function testElMenuOfreceCancelarSoloConElPagoPendiente(): void
    {
        $client = $this->createAuthenticatedClient();
        $pendiente = $this->prepararPedido();
        $pagado = $this->prepararPedido(pagado: true);

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $pendiente->getRound()->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action$="/orders/' . $pendiente->getId() . '/cancel"]'));

        $crawler = $client->request('GET', '/gestion/consumer-group/' . $pagado->getRound()->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/orders/' . $pagado->getId() . '/cancel"]'));
    }

    /**
     * Una ronda abierta con un pedido de 4 kg de una socia, pagado o no.
     */
    private function prepararPedido(bool $pagado = false): ConsumerGroupOrder
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
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
     * abierta, que sólo existe tras la primera petición. Si el formulario no está,
     * el test no tiene nada que comprobar y falla en vez de seguir con un token falso.
     */
    private function cancelToken(KernelBrowser $client, ConsumerGroupOrder $order): string
    {
        $crawler = $client->request('GET', '/gestion/consumer-group/' . $order->getRound()->getId());
        self::assertResponseIsSuccessful();
        $field = $crawler->filter('form[action$="/orders/' . $order->getId() . '/cancel"] input[name="_token"]');
        self::assertCount(1, $field, 'El menú del pedido pendiente debería ofrecer cancelar.');

        return $field->attr('value');
    }

    /**
     * Cambia el estado del pedido en la base, tras haber pedido el token.
     *
     * @param callable(ConsumerGroupOrder): mixed $change
     */
    private function modificar(ConsumerGroupOrder $order, callable $change): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $change($em->getRepository(ConsumerGroupOrder::class)->find($order->getId()));
        $em->flush();
    }

    private function countEmptiedEvents(ConsumerGroupOrder $order): int
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $events = $em->getRepository(ConsumerGroupEventLog::class)->findBy(['round' => $order->getRound()->getId()]);

        return count(array_filter($events, static fn (ConsumerGroupEventLog $e): bool => $e->getKind() === ConsumerGroupEventLog::KIND_ORDER_EMPTIED));
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
