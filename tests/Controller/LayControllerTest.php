<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de puestas de huevos.
 */
class LayControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/lay/ con admin logueado devuelve 200.
     */
    public function testLayListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/lay/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
