<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de tandas.
 */
class BatchControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/batch/ con admin logueado devuelve 200.
     */
    public function testBatchListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/batch/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
