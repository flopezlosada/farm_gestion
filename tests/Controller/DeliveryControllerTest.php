<?php

namespace App\Tests\Controller;

use App\Entity\Basket;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Smoke + casos del DeliveryController: las pantallas centrales del reparto del
 * día a día (listado v2 por nodo, ficha del día e impresión del listado).
 *
 * Hasta ahora el DeliveryController no tenía test propio pese a ser reparto —
 * sí lo tenían el CRUD de excepciones y los cambios de viernes. Este cierra ese
 * hueco y, en particular, fija el contrato del listado imprimible: no se genera
 * un PDF cuando ningún nodo elegido reparte ese día.
 *
 * Apoyado en las fixtures de db_test: NodeFixtures siembra Torremocha (id 1,
 * semanal, viernes), Cascorro (id 2) y Midori (id 3); PartnerUserFixtures
 * siembra dos Baskets en viernes consecutivos con socios que recogen, así que
 * Torremocha reparte siempre en ellos.
 */
class DeliveryControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/reparto/v2 (sin nodo/basket) resuelve el primer nodo y el
     * próximo basket y redirige a la pantalla v2 concreta.
     */
    public function testByNodeDefaultRedirects(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/reparto/v2');

        $this->assertResponseRedirects();
    }

    /**
     * GET /gestion/reparto/v2/nodo/{nodeId}/{basketId} para Torremocha devuelve
     * 200 (el listado de reparto del día por nodo).
     */
    public function testByNodeReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $basketId = $this->firstBasketId();

        $client->request('GET', sprintf('/gestion/reparto/v2/nodo/1/%d', $basketId));

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET /gestion/reparto/dia/{basketId} (puente por día, lo usa el calendario)
     * resuelve el primer nodo y redirige a la pantalla v2 de ese día. Reemplaza
     * al antiguo delivery_show, retirado al unificar el reparto en la v2.
     */
    public function testForBasketRedirectsToV2(): void
    {
        $client = $this->createAuthenticatedClient();
        $basketId = $this->firstBasketId();

        $client->request('GET', sprintf('/gestion/reparto/dia/%d', $basketId));

        $this->assertResponseRedirects();
        $this->assertStringContainsString(
            '/v2/nodo/',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * GET /gestion/reparto/imprimible/{basketId} (selector de nodos a imprimir)
     * devuelve 200.
     */
    public function testPrintableSelectReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $basketId = $this->firstBasketId();

        $client->request('GET', sprintf('/gestion/reparto/imprimible/%d', $basketId));

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Sin nodos seleccionados (o con nodos que no reparten) el PDF saldría con
     * cero hojas: el controller no lo genera y vuelve al selector con un aviso.
     */
    public function testPrintablePdfWithoutNodesRedirectsToSelect(): void
    {
        $client = $this->createAuthenticatedClient();
        $basketId = $this->firstBasketId();

        $client->request('GET', sprintf('/gestion/reparto/imprimible/%d/pdf', $basketId));

        $this->assertResponseRedirects();
        $this->assertStringContainsString(
            '/imprimible/',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * Con un nodo que sí reparte (Torremocha, semanal viernes), el listado se
     * genera y devuelve un PDF.
     */
    public function testPrintablePdfWithDeliveringNodeReturnsPdf(): void
    {
        $client = $this->createAuthenticatedClient();
        $basketId = $this->firstBasketId();

        $client->request('GET', sprintf('/gestion/reparto/imprimible/%d/pdf', $basketId), ['nodes' => ['1']]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString(
            'application/pdf',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testMonthlyPrintableSelectReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/reparto/imprimible-mensual');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Sin ym (o con ym mal formado) el PDF mensual no se genera: vuelve al selector.
     */
    public function testMonthlyPdfWithBadYmRedirectsToSelect(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/reparto/imprimible-mensual/pdf', ['ym' => 'no-valido']);

        $this->assertResponseRedirects();
        $this->assertStringContainsString(
            '/imprimible-mensual',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * Con un mes que tiene viernes sembrados y un nodo que reparte, el listado
     * mensual (matriz) se genera y devuelve un PDF.
     */
    public function testMonthlyPdfWithValidMonthReturnsPdf(): void
    {
        $client = $this->createAuthenticatedClient();
        $ym = $this->firstBasket()->getDate()->format('Y-m');

        $client->request('GET', '/gestion/reparto/imprimible-mensual/pdf', ['ym' => $ym, 'nodes' => ['1']]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString(
            'application/pdf',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    /**
     * Id del primer Basket sembrado (el más próximo en el tiempo). Se resuelve
     * por repositorio en vez de hardcodear porque el id es autogenerado.
     *
     * @return int
     */
    private function firstBasketId(): int
    {
        return $this->firstBasket()->getId();
    }

    /**
     * Primer Basket sembrado (el más próximo en el tiempo).
     */
    private function firstBasket(): Basket
    {
        $basket = static::getContainer()
            ->get('doctrine')
            ->getRepository(Basket::class)
            ->findOneBy([], ['date' => 'ASC']);

        if ($basket === null) {
            throw new \RuntimeException('Fixtures sin Basket; carga PartnerUserFixtures en db_test antes de correr los tests.');
        }

        return $basket;
    }
}
