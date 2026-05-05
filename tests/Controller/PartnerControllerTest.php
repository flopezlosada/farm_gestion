<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de socixs.
 */
class PartnerControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/partner/ con admin logueado devuelve 200.
     */
    public function testPartnerListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
