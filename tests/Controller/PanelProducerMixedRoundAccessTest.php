<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Producer;
use App\Service\AppSettings;

/**
 * Un productor autogestionado solo ve y toca los pedidos que son SOLO suyos. Un pedido
 * con varios productores es de la comisión: ahí el panel enseñaría el agregado y los
 * productos de los demás, y dejaría editarlos o cancelar el pedido entero.
 */
class PanelProducerMixedRoundAccessTest extends AbstractAuthenticatedTest
{
    public function testElPanelAtiendeUnPedidoSoloSuyo(): void
    {
        [$client, , $round] = $this->escenario(false);

        $client->request('GET', '/panel/producer/rounds/' . $round->getId());

        self::assertResponseIsSuccessful();
    }

    public function testUnPedidoConVariosProductoresDaNotFoundEnTodasLasRutas(): void
    {
        [$client, , $round] = $this->escenario(true);
        $id = $round->getId();

        foreach (['GET' => ['/panel/producer/rounds/' . $id, '/panel/producer/rounds/' . $id . '/items', '/panel/producer/rounds/' . $id . '/export'], 'POST' => ['/panel/producer/rounds/' . $id . '/transition']] as $method => $uris) {
            foreach ($uris as $uri) {
                $client->request($method, $uri);
                self::assertResponseStatusCodeSame(404, $method . ' ' . $uri);
            }
        }
    }

    public function testElListadoDelPanelNoIncluyeLosPedidosCompartidos(): void
    {
        [$client] = $this->escenario(true);

        $crawler = $client->request('GET', '/panel/producer');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Pedido compartido', $crawler->text());
    }

    /**
     * Un productor con acceso al panel y un pedido suyo, solo o compartido con otro.
     *
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: Producer, 2: ConsumerGroupRound}
     */
    private function escenario(bool $compartido): array
    {
        $client = static::createClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $em = static::getContainer()->get('doctrine')->getManager();

        $propio = (new Producer())->setName('Propio ' . uniqid())->setSelfManaged(!$compartido);
        $otro = (new Producer())->setName('Otro ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit($this->unidadGlobal('kg'));
        $propio->addProduct($naranjas);

        $round = (new ConsumerGroupRound())->setTitle($compartido ? 'Pedido compartido' : 'Pedido propio')
            ->setOrdersCloseAt(new \DateTime('+5 days'))->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_NONE);
        $round->addProducer($propio);
        if ($compartido) {
            $round->addProducer($otro);
        }
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);
        foreach ([$propio, $otro, $naranjas, $round, $item] as $entity) {
            $em->persist($entity);
        }

        $user = $this->createUserWithRoles([]);
        $user->setProducer($propio);
        $em->flush();
        $client->loginUser($user);

        return [$client, $propio, $round];
    }
}
