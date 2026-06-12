<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de eventos públicos (Booking).
 */
class BookingControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/eventos/ con admin logueado devuelve 200.
     */
    public function testBookingListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/eventos/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
