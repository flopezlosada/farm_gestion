<?php

namespace App\Tests\Controller;

/**
 * Smoke tests del CRUD de excepciones de calendario de reparto.
 * Sub-fase 8.8d (2026-05-27).
 */
class DeliveryExceptionControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/reparto/excepciones/ con admin logueado devuelve 200.
     */
    public function testIndexReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/reparto/excepciones/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET /gestion/reparto/excepciones/new con admin logueado devuelve 200.
     */
    public function testNewReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/reparto/excepciones/new');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
