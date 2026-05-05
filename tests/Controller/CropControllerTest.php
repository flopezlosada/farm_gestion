<?php

namespace App\Tests\Controller;

/**
 * Smoke test del listado de cultivos.
 */
class CropControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/crop/ con admin logueado devuelve 200.
     */
    public function testCropListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/crop/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
