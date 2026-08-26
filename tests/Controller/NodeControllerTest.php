<?php

namespace App\Tests\Controller;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Smoke tests del CRUD de nodos físicos de reparto.
 * Sub-fase 8.8a (2026-05-26). Ficha + gestión de grupos añadidas en el
 * rediseño de reparto.
 */
class NodeControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/node/{id} (ficha) devuelve 200 para los nodos sembrados.
     */
    public function testNodeShowReturnsOkForSeededNodes(): void
    {
        $client = $this->createAuthenticatedClient();

        foreach ([1, 2, 3, 4] as $nodeId) {
            $client->request('GET', sprintf('/gestion/node/%d', $nodeId));
            $this->assertSame(
                200,
                $client->getResponse()->getStatusCode(),
                sprintf('La ficha del nodo id=%d debería devolver 200.', $nodeId)
            );
        }
    }

    /**
     * Engancha un grupo a un nodo desde la ficha y luego lo desengancha,
     * comprobando que la FK weekly_basket_group.node_id se actualiza.
     * Autocontenido: crea su propio nodo y grupo, y los borra al final,
     * para no depender de datos sembrados ni ensuciar db_test.
     */
    public function testAttachAndDetachGroupToNode(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $node = (new Node())
            ->setName('TEST Nodo ' . uniqid())
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
        $group = (new WeeklyBasketGroup())
            ->setName('TEST Grupo ' . uniqid())
            ->setColor('#abcabc');
        $em->persist($node);
        $em->persist($group);
        $em->flush();
        $nodeId = $node->getId();
        $groupId = $group->getId();

        // Enganchar: el form de la ficha trae el select de grupos sin nodo.
        $crawler = $client->request('GET', sprintf('/gestion/node/%d', $nodeId));
        $attachForm = $crawler->filter(sprintf('form[action="/gestion/node/%d/grupos"]', $nodeId))->form();
        $attachForm['group_id'] = (string) $groupId;
        $client->submit($attachForm);
        $this->assertResponseRedirects(sprintf('/gestion/node/%d', $nodeId));

        $em = static::getContainer()->get('doctrine')->getManager();
        $attached = $em->getRepository(WeeklyBasketGroup::class)->find($groupId);
        $this->assertNotNull($attached->getNode(), 'El grupo debería haber quedado asignado al nodo.');
        $this->assertSame($nodeId, $attached->getNode()->getId());

        // Desenganchar mediante el botón de la fila del grupo.
        $crawler = $client->request('GET', sprintf('/gestion/node/%d', $nodeId));
        $detachForm = $crawler->filter(sprintf('form[action="/gestion/node/%d/grupos/%d/quitar"]', $nodeId, $groupId))->form();
        $client->submit($detachForm);
        $this->assertResponseRedirects(sprintf('/gestion/node/%d', $nodeId));

        $em = static::getContainer()->get('doctrine')->getManager();
        $detached = $em->getRepository(WeeklyBasketGroup::class)->find($groupId);
        $this->assertNull($detached->getNode(), 'El grupo debería haber quedado sin nodo.');

        // Limpieza.
        $em->remove($em->getRepository(WeeklyBasketGroup::class)->find($groupId));
        $em->remove($em->getRepository(Node::class)->find($nodeId));
        $em->flush();
    }

    /**
     * GET /gestion/node/ con admin logueado devuelve 200.
     */
    public function testNodeIndexReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/node/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET /gestion/node/new con admin logueado devuelve 200.
     */
    public function testNodeNewReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/node/new');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * El horario público (Node.schedule) se puede editar desde el form
     * de edición y persiste. Autocontenido: crea su propio nodo y lo borra.
     */
    public function testEditPersistsPublicSchedule(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $node = (new Node())
            ->setName('TEST Nodo horario ' . uniqid())
            ->setDeliveryWeekday(3)
            ->setCadence(Node::CADENCE_WEEKLY);
        $em->persist($node);
        $em->flush();
        $nodeId = $node->getId();

        $crawler = $client->request('GET', sprintf('/gestion/node/%d/edit', $nodeId));
        $form = $crawler->filter('form[name="node"]')->form();
        $form['node[schedule]'] = 'Miércoles de 19:00 a 21:00';
        $client->submit($form);
        $this->assertResponseRedirects('/gestion/node/', message: 'El submit del form de edición debería redirigir.');

        $em = static::getContainer()->get('doctrine')->getManager();
        $reloaded = $em->getRepository(Node::class)->find($nodeId);
        $this->assertSame(
            'Miércoles de 19:00 a 21:00',
            $reloaded->getSchedule(),
            'El horario público debería haberse guardado desde el form de edición.'
        );

        // Limpieza.
        $em->remove($reloaded);
        $em->flush();
    }

    /**
     * GET /gestion/node/{id}/edit con admin logueado devuelve 200 para los
     * nodos sembrados, uno por cadencia: Torremocha (semanal), Cascorro y
     * Midori (quincenales) y El Berrueco (mensual).
     */
    public function testNodeEditReturnsOkForSeededNodes(): void
    {
        $client = $this->createAuthenticatedClient();

        foreach ([1, 2, 3, 4] as $nodeId) {
            $client->request('GET', sprintf('/gestion/node/%d/edit', $nodeId));
            $this->assertSame(
                200,
                $client->getResponse()->getStatusCode(),
                sprintf('Edit del nodo id=%d debería devolver 200.', $nodeId)
            );
        }
    }

    /**
     * Cambiar la cadencia de un punto de forma que deje cestas fuera NO se
     * guarda: se rechaza y se listan los socios a corregir primero. Pasar un
     * punto semanal a quincenal dejaría sus cestas semanales sin poder
     * repartirse, y a qué modalidad pasa cada socio es decisión de
     * administración, no del sistema.
     */
    public function testCadenceChangeIsBlockedWhenSharesNoLongerFit(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $surname = 'Semanal Bloqueo ' . uniqid();
        [$nodeId, $groupId, $partnerId, $shareId] = $this->seedNodeWithShare(
            $em,
            (new Node())->setName('TEST Nodo bloqueo ' . uniqid())->setDeliveryWeekday(5)->setCadence(Node::CADENCE_WEEKLY),
            BasketShare::IDS_WEEKLY[0],
            $surname,
        );

        $crawler = $client->request('GET', sprintf('/gestion/node/%d/edit', $nodeId));
        $form = $crawler->filter('form[name="node"]')->form();
        $form['node[cadence]'] = Node::CADENCE_BIWEEKLY;
        $form['node[anchorDate]'] = '2026-09-04'; // viernes, el día de reparto del punto
        $client->submit($form);

        $this->assertSame(
            200,
            $client->getResponse()->getStatusCode(),
            'El cambio de cadencia con cestas incompatibles no debe redirigir: se rechaza y se repinta el form.'
        );
        $this->assertStringContainsString(
            $surname,
            (string) $client->getResponse()->getContent(),
            'La pantalla debe listar al socio cuya cesta se quedaría fuera.'
        );

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $this->assertSame(
            Node::CADENCE_WEEKLY,
            $em->getRepository(Node::class)->find($nodeId)->getCadence(),
            'La cadencia no debe haberse guardado.'
        );

        $this->cleanUpSeed($em, $nodeId, $groupId, $partnerId, $shareId);
    }

    /**
     * Cambiar la SEMANA de un punto mensual sí se propaga: sus socios recogen
     * la que abra el punto, no hay nada que decidir. Regresión de la
     * incoherencia que dejaba la ficha del socio diciendo una semana y el punto
     * abriendo otra.
     */
    public function testMonthlyWeekChangePropagatesToShares(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        [$nodeId, $groupId, $partnerId, $shareId] = $this->seedNodeWithShare(
            $em,
            (new Node())->setName('TEST Nodo mensual ' . uniqid())->setDeliveryWeekday(3)->setCadence(Node::CADENCE_MONTHLY)->setMonthlyWeek(1),
            BasketShare::ID_MONTHLY,
            'Mensual Propagacion ' . uniqid(),
            1,
        );

        $crawler = $client->request('GET', sprintf('/gestion/node/%d/edit', $nodeId));
        $form = $crawler->filter('form[name="node"]')->form();
        $form['node[monthlyWeek]'] = '2';
        $client->submit($form);
        $this->assertResponseRedirects('/gestion/node/');

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $this->assertSame(
            2,
            $em->getRepository(PartnerBasketShare::class)->find($shareId)->getDayMonthOrder(),
            'La cesta del socio debe pasar a la entrega que ahora abre el punto.'
        );

        $this->cleanUpSeed($em, $nodeId, $groupId, $partnerId, $shareId);
    }

    /**
     * Enganchar un grupo a un punto mueve de golpe a todos sus socios, así que
     * se rechaza si alguna de sus cestas no se podría repartir ahí. Antes se
     * asignaba con un setNode a pelo, sin mirar nada.
     */
    public function testAttachGroupIsBlockedWhenSharesDoNotFit(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        // Punto quincenal destino, y un grupo huérfano con un socio semanal.
        $node = (new Node())
            ->setName('TEST Nodo quincenal destino ' . uniqid())
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_BIWEEKLY)
            ->setAnchorDate(new \DateTimeImmutable('2026-09-04'));
        $group = (new WeeklyBasketGroup())
            ->setName('TEST Grupo huérfano ' . uniqid())
            ->setColor('#abcabc');
        $surname = 'Semanal Huerfano ' . uniqid();
        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname($surname)
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setWeeklyBasketGroup($group);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find(BasketShare::IDS_WEEKLY[0]));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2099-01-01'));

        $em->persist($node);
        $em->persist($group);
        $em->persist($partner);
        $em->persist($share);
        $em->flush();
        [$nodeId, $groupId, $partnerId, $shareId] = [$node->getId(), $group->getId(), $partner->getId(), $share->getId()];

        $crawler = $client->request('GET', sprintf('/gestion/node/%d', $nodeId));
        $attachForm = $crawler->filter(sprintf('form[action="/gestion/node/%d/grupos"]', $nodeId))->form();
        $attachForm['group_id'] = (string) $groupId;
        $client->submit($attachForm);
        $this->assertResponseRedirects(sprintf('/gestion/node/%d', $nodeId));

        $crawler = $client->followRedirect();
        $this->assertStringContainsString(
            'no se podrían repartir',
            $crawler->filter('body')->text(),
            'Debe avisar de por qué no se ha enganchado el grupo.'
        );

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $this->assertNull(
            $em->getRepository(WeeklyBasketGroup::class)->find($groupId)->getNode(),
            'El grupo no debe haber quedado asignado al punto quincenal.'
        );

        $this->cleanUpSeed($em, $nodeId, $groupId, $partnerId, $shareId);
    }

    /**
     * Punto + grupo + socio + cesta activa, todo nuevo y desechable.
     *
     * @param EntityManagerInterface $em
     * @param Node                   $node          Punto ya configurado (cadencia incluida).
     * @param int                    $basketShareId Modalidad de la cesta del socio.
     * @param string                 $surname       Apellido único, para localizarlo en el HTML.
     * @param int|null               $dayMonthOrder Entrega del mes, sólo en las mensuales.
     * @return int[] [nodeId, groupId, partnerId, shareId]
     */
    private function seedNodeWithShare(
        EntityManagerInterface $em,
        Node $node,
        int $basketShareId,
        string $surname,
        ?int $dayMonthOrder = null,
    ): array {
        $group = (new WeeklyBasketGroup())
            ->setName('TEST Grupo ' . uniqid())
            ->setColor('#abcabc')
            ->setNode($node);
        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname($surname)
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setWeeklyBasketGroup($group);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find($basketShareId));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        // Fecha lejana: el reconcile posterior no tiene semanas que materializar.
        $share->setStartDate(new \DateTime('2099-01-01'));
        $share->setDayMonthOrder($dayMonthOrder);

        $em->persist($node);
        $em->persist($group);
        $em->persist($partner);
        $em->persist($share);
        $em->flush();

        return [$node->getId(), $group->getId(), $partner->getId(), $share->getId()];
    }

    /**
     * @param EntityManagerInterface $em
     */
    private function cleanUpSeed(EntityManagerInterface $em, int $nodeId, int $groupId, int $partnerId, int $shareId): void
    {
        $em->remove($em->getRepository(PartnerBasketShare::class)->find($shareId));
        $em->flush();
        $em->remove($em->getRepository(Partner::class)->find($partnerId));
        $em->flush();
        $em->remove($em->getRepository(WeeklyBasketGroup::class)->find($groupId));
        $em->remove($em->getRepository(Node::class)->find($nodeId));
        $em->flush();
    }
}
