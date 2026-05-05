<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de grupos de reparto semanal.
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
}
