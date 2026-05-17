<?php

namespace App\Tests\Controller;

/**
 * Smoke test de la pantalla admin de cambios puntuales de viernes.
 * Solo verifica que renderiza con 200 con un admin logueado.
 */
class DeliveryShiftsAdminTest extends AbstractAuthenticatedTest
{
    public function testDeliveryShiftsIndexReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/reparto/cambios-viernes');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
