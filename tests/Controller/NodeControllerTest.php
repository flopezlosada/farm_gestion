<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\WeeklyBasketGroup;

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

        foreach ([1, 2, 3] as $nodeId) {
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
     * GET /gestion/node/{id}/edit con admin logueado devuelve 200
     * para los 3 nodos sembrados durante 8.8a (Torremocha, Cascorro, Midori).
     */
    public function testNodeEditReturnsOkForSeededNodes(): void
    {
        $client = $this->createAuthenticatedClient();

        foreach ([1, 2, 3] as $nodeId) {
            $client->request('GET', sprintf('/gestion/node/%d/edit', $nodeId));
            $this->assertSame(
                200,
                $client->getResponse()->getStatusCode(),
                sprintf('Edit del nodo id=%d debería devolver 200.', $nodeId)
            );
        }
    }
}
