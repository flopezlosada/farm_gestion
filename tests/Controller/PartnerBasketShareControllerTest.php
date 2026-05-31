<?php

namespace App\Tests\Controller;

use App\Entity\Partner;

/**
 * Smoke test del listado de cestas por socix y del cambio de cesta con histórico.
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

    /**
     * GET de la pantalla de cambio de cesta (con histórico) de una cesta activa
     * devuelve 200 y muestra el copy "Cambiar la cesta" (no "modalidad").
     */
    public function testChangeBasketFormRenders(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $activeShare = null;
        foreach ($em->getRepository(Partner::class)->findAll() as $partner) {
            if ($partner->hasActiveBasket()) {
                $activeShare = $partner->getActiveBasket();
                break;
            }
        }
        $this->assertNotNull($activeShare, 'Fixtures sin socix con cesta activa.');

        $client->request('GET', '/gestion/partner/basket/share/' . $activeShare->getId() . '/change-modality');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSelectorTextContains('body', 'Cambiar la cesta');
    }
}
