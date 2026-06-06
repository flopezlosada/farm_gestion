<?php

namespace App\Tests\Controller;

/**
 * Smoke tests del panel del socix. Verifican que las pantallas
 * renderizan con 200 cuando el socix está logueado, y que un
 * usuario no logueado se redirige al login.
 */
class PanelControllerTest extends AbstractPartnerAuthenticatedTest
{
    public function testPanelIndexReturnsOk(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $client->request('GET', '/panel');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testPanelCalendarReturnsOk(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $client->request('GET', '/panel/calendar');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testPanelBasketRedirectsToCalendar(): void
    {
        // El "Mi cesta" antiguo se retiró: su gestión vive en el calendario de recogida.
        // La ruta se mantiene como redirección para enlaces/marcadores viejos.
        $client = $this->createPartnerAuthenticatedClient();
        $client->request('GET', '/panel/cesta');

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertStringEndsWith('/panel/calendar', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testPanelProfileReturnsOk(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $client->request('GET', '/panel/perfil');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testPanelFamilyReturnsOk(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $client->request('GET', '/panel/familia');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testPanelRequiereLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/panel');

        // Sin login, Symfony redirige al login (302) o devuelve 401/403.
        $code = $client->getResponse()->getStatusCode();
        $this->assertContains($code, [302, 401, 403], sprintf('Esperaba redirect o forbidden sin login, obtenido %d', $code));
    }
}
