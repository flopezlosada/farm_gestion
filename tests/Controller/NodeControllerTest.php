<?php

namespace App\Tests\Controller;

/**
 * Smoke tests del CRUD de nodos físicos de reparto.
 * Sub-fase 8.8a (2026-05-26).
 */
class NodeControllerTest extends AbstractAuthenticatedTest
{
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
