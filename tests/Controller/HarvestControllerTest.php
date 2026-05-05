<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de cosechas.
 */
class HarvestControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/harvest/ con admin logueado devuelve 200.
     */
    public function testHarvestListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/harvest/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
