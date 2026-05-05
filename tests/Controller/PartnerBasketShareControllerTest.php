<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de cestas por socix.
 */
class PartnerBasketShareControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/partner/basket/share/ con admin logueado devuelve 200.
     */
    public function testPartnerBasketShareListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/basket/share/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
