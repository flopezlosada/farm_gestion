<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de reservas.
 */
class BookingControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/booking/ con admin logueado devuelve 200.
     */
    public function testBookingListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/booking/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
