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
     * GET del listado de cestas activas devuelve 200 y pinta la cabecera con
     * el total — wiring de la action tras retirar los ifs con ids hardcodeados
     * que contaban mal mensual y compartidas. La aritmética del total la cubre
     * BasketShareEquivalenceTest (asertar aquí el número exacto recalculándolo
     * con el mismo código sería tautológico).
     */
    public function testActiveListShowsCatalogTotal(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/basket/share/status/1');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSelectorTextContains('body', 'Total actual:');
    }

    /**
     * GET del formulario de finalizar cesta (al que ahora se llega por enlace
     * directo desde la ficha, sin diálogo intermedio) devuelve 200 y pide la
     * fecha de fin.
     */
    public function testFinalizeFormRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/partner/basket/share/' . $this->findActiveShareId() . '/finalize');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSelectorTextContains('body', 'Fecha de fin');
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
