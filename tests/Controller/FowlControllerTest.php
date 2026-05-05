<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de aves.
 */
class FowlControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/fowl/ con admin logueado devuelve 200.
     */
    public function testFowlListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/fowl/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
