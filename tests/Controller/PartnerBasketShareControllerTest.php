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

        $client->request('GET', '/gestion/partner/basket/share/' . $this->findActiveShareId() . '/change-modality');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSelectorTextContains('body', 'Cambiar la cesta');
    }

    /**
     * GET del formulario de corrección in situ (edit) devuelve 200 — cubre el
     * wiring de la action (inyección de WeeklyBasketGenerator para la cascada
     * de reconciliación sobre semanas ya generadas).
     */
    public function testEditFormRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/partner/basket/share/' . $this->findActiveShareId() . '/edit');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Id de una PBS activa cualquiera de las fixtures.
     *
     * @return int
     */
    private function findActiveShareId(): int
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        foreach ($em->getRepository(Partner::class)->findAll() as $partner) {
            if ($partner->hasActiveBasket()) {
                return $partner->getActiveBasket()->getId();
            }
        }

        $this->fail('Fixtures sin socix con cesta activa.');
    }
}
