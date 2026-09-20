<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupUnit;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Entity\User;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Quitar un producto de una ronda con pedidos ya hechos exige confirmación
 * explícita antes de aplicar el cambio, y avisa a las socias afectadas.
 *
 * Sin esto, un click sin querer en "Guardar productos" borra en cascada
 * pedidos reales sin dejar rastro ni avisar a quien los hizo.
 */
class ConsumerGroupItemsRemovalConfirmationTest extends AbstractAuthenticatedTest
{
    public function testQuitarProductoConPedidosPideConfirmacionSinAplicarNada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas, $aceite, $partner] = $this->prepararRondaConPedido();

        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', [
            '_token'   => $this->csrfToken($client, $round),
            'include'  => [$aceite->getId() => '1'],
            'price'    => [$naranjas->getId() => '2.50', $aceite->getId() => '8.00'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cg-removal-warning', 'Naranjas');
        self::assertSelectorTextContains('.cg-removal-warning', 'Socia Uno');

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $refreshedRound = $em->getRepository(ConsumerGroupRound::class)->find($round->getId());
        self::assertCount(1, $refreshedRound->getItems(), 'no debe tocar los items de la ronda sin confirmar');
        $recipientUser = $em->getRepository(User::class)->findOneBy(['partner' => $partner->getId()]);
        self::assertCount(0, $em->getRepository(Notification::class)->findBy(['recipient' => $recipientUser]), 'no debe avisar sin confirmar');
    }

    public function testQuitarProductoConPedidosConfirmadoAplicaYAvisa(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [$round, $naranjas, $aceite, $partner] = $this->prepararRondaConPedido();

        $client->request('POST', '/gestion/consumer-group/' . $round->getId() . '/items', [
            '_token'         => $this->csrfToken($client, $round),
            'include'        => [$aceite->getId() => '1'],
            'price'          => [$naranjas->getId() => '2.50', $aceite->getId() => '8.00'],
            'confirm_removal' => '1',
        ]);

        self::assertResponseRedirects('/gestion/consumer-group/' . $round->getId());

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $refreshedRound = $em->getRepository(ConsumerGroupRound::class)->find($round->getId());
        $productIds = array_map(
            static fn (ConsumerGroupRoundItem $item) => $item->getProduct()?->getId(),
            $refreshedRound->getItems()->toArray(),
        );
        self::assertNotContains($naranjas->getId(), $productIds, 'Naranjas debe haberse quitado');
        self::assertContains($aceite->getId(), $productIds, 'Aceite debe haberse añadido');

        $recipientUser = $em->getRepository(User::class)->findOneBy(['partner' => $partner->getId()]);
        $notifications = $em->getRepository(Notification::class)->findBy(['recipient' => $recipientUser]);
        self::assertCount(1, $notifications);
        self::assertSame(Notification::KIND_CONSUMER_GROUP_ITEMS_CHANGED, $notifications[0]->getKind());
    }

    /**
     * @return array{0: ConsumerGroupRound, 1: ConsumerGroupProduct, 2: ConsumerGroupProduct, 3: Partner}
     */
    private function prepararRondaConPedido(): array
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $producer = (new Producer())->setName('Huerta Test');
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit((new ConsumerGroupUnit())->setName('kg'));
        $aceite = (new ConsumerGroupProduct())->setName('Aceite')->setUnit((new ConsumerGroupUnit())->setName('L'));
        $producer->addProduct($naranjas);
        $producer->addProduct($aceite);

        $round = new ConsumerGroupRound();
        $round->setTitle('Ronda de test')->setProducer($producer)->setOrdersCloseAt(new \DateTime('tomorrow'));
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);

        $suffix = uniqid();
        $partner = (new Partner())->setname('Socia')->setSurname('Uno');
        $user = (new User())
            ->setUsername('socia-test-' . $suffix)
            ->setEmail('socia-test-' . $suffix . '@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles(['ROLE_PARTNER'])
            ->setPartner($partner);

        $order = new ConsumerGroupOrder($round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, '3'));

        $em->persist($producer);
        $em->persist($naranjas);
        $em->persist($aceite);
        $em->persist($round);
        $em->persist($item);
        $em->persist($partner);
        $em->persist($user);
        $em->persist($order);
        $em->flush();

        return [$round, $naranjas, $aceite, $partner];
    }

    /**
     * El token de `security.csrf.token_manager` exige una sesión ya abierta, que
     * sólo existe tras la primera petición HTTP. Igual que en el resto de tests
     * funcionales del proyecto, se coge del formulario ya renderizado por GET.
     */
    private function csrfToken(KernelBrowser $client, ConsumerGroupRound $round): string
    {
        $crawler = $client->request('GET', '/gestion/consumer-group/' . $round->getId() . '/items');

        return $crawler->filter('input[name="_token"]')->attr('value');
    }
}
