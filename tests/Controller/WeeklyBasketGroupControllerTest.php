<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\WeeklyBasketGroup;

/**
 * Tests del listado y la ficha de grupos de recogida, incluida la gestión
 * de membresía (añadir / quitar socios).
 */
class WeeklyBasketGroupControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/weekly/basket/group/ con admin logueado devuelve 200.
     */
    public function testWeeklyBasketGroupListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/weekly/basket/group/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * La ficha de un grupo sembrado devuelve 200.
     */
    public function testShowReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/weekly/basket/group/1');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Añade un socio al grupo desde la ficha y luego lo quita, comprobando
     * que Partner.weekly_basket_group se actualiza. Autocontenido: crea su
     * propio grupo y socio y los borra al final, para no ensuciar db_test.
     */
    public function testAddAndRemovePartner(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $group = (new WeeklyBasketGroup())->setName('TEST Grupo ' . uniqid())->setColor('#abcabc');
        $partner = (new Partner())
            ->setname('TEST')
            ->setSurname('Socio ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $em->persist($group);
        $em->persist($partner);
        $em->flush();
        $groupId = $group->getId();
        $partnerId = $partner->getId();

        // Añadir: la ficha lista los socios SIN grupo como botones; se pulsa
        // el del socio creado (cada botón es un submit con name=partner_id).
        $crawler = $client->request('GET', sprintf('/gestion/weekly/basket/group/%d', $groupId));
        $addForm = $crawler->filter(sprintf('form[action="/gestion/weekly/basket/group/%d/socios"] button[value="%d"]', $groupId, $partnerId))->form();
        $client->submit($addForm);
        $this->assertResponseRedirects(sprintf('/gestion/weekly/basket/group/%d', $groupId));

        $em = static::getContainer()->get('doctrine')->getManager();
        $added = $em->getRepository(Partner::class)->find($partnerId);
        $this->assertNotNull($added->getWeeklyBasketGroup());
        $this->assertSame($groupId, $added->getWeeklyBasketGroup()->getId());

        // Quitar mediante el botón de su fila.
        $crawler = $client->request('GET', sprintf('/gestion/weekly/basket/group/%d', $groupId));
        $removeForm = $crawler->filter(sprintf('form[action="/gestion/weekly/basket/group/%d/socios/%d/quitar"]', $groupId, $partnerId))->form();
        $client->submit($removeForm);
        $this->assertResponseRedirects(sprintf('/gestion/weekly/basket/group/%d', $groupId));

        $em = static::getContainer()->get('doctrine')->getManager();
        $removed = $em->getRepository(Partner::class)->find($partnerId);
        $this->assertNull($removed->getWeeklyBasketGroup());

        // Limpieza.
        $em->remove($em->getRepository(Partner::class)->find($partnerId));
        $em->remove($em->getRepository(WeeklyBasketGroup::class)->find($groupId));
        $em->flush();
    }

    /**
     * Reasigna el nodo de un grupo desde el desplegable inline del listado.
     * Filtra por nombre único para que el grupo caiga en la primera página y
     * su form sea localizable. Autocontenido: crea grupo + nodo y limpia.
     */
    public function testSetNodeInline(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $name = 'ZZTEST Grupo ' . uniqid();
        $group = (new WeeklyBasketGroup())->setName($name)->setColor('#abcabc');
        $node = (new Node())->setName('ZZTEST Nodo ' . uniqid())->setDeliveryWeekday(5)->setCadence(Node::CADENCE_WEEKLY);
        $em->persist($group);
        $em->persist($node);
        $em->flush();
        $groupId = $group->getId();
        $nodeId = $node->getId();

        // El nodo se cambia pulsando el botón-opción del nodo dentro del
        // popover de la fila (cada opción es un submit con name=node_id).
        $crawler = $client->request('GET', '/gestion/weekly/basket/group/?q=' . urlencode($name));
        $form = $crawler->filter(sprintf(
            'form[action="/gestion/weekly/basket/group/%d/nodo"] button[value="%d"]',
            $groupId,
            $nodeId
        ))->form();
        $client->submit($form);
        $this->assertResponseRedirects();

        $em = static::getContainer()->get('doctrine')->getManager();
        $reloaded = $em->getRepository(WeeklyBasketGroup::class)->find($groupId);
        $this->assertNotNull($reloaded->getNode());
        $this->assertSame($nodeId, $reloaded->getNode()->getId());

        // Limpieza: primero desvincula para poder borrar el nodo sin FK.
        $reloaded->setNode(null);
        $em->flush();
        $em->remove($em->getRepository(WeeklyBasketGroup::class)->find($groupId));
        $em->remove($em->getRepository(Node::class)->find($nodeId));
        $em->flush();
    }
}
