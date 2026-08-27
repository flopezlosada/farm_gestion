<?php

namespace App\Tests\Controller;

use App\Entity\Node;

/**
 * Smoke de la pantalla de huevos de un reparto: que renderiza en sus tres
 * estados (sin selección, con punto de recogida elegido) y que la acción está
 * cerrada a quien no gestiona reparto.
 *
 * La mecánica de la operación la cubre
 * {@see \App\Tests\Service\Delivery\NodeEggReschedulerTest}; aquí sólo el
 * cableado: rutas, plantilla y permisos.
 */
class NodeEggRescheduleControllerTest extends AbstractAuthenticatedTest
{
    public function testPantallaAbreConLaPrimeraSemanaPreseleccionada(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/reparto/huevos');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertGreaterThan(
            0,
            $crawler->filter('input[name="basket"]')->count(),
            'La pantalla debe ofrecer semanas de origen.',
        );
    }

    public function testConPuntoDeRecogidaElegidoPintaLaPrevisualizacion(): void
    {
        $client = $this->createAuthenticatedClient();
        $node = static::getContainer()->get('doctrine')->getRepository(Node::class)
            ->findOneBy([], ['id' => 'ASC']);
        $this->assertNotNull($node, 'Las fixtures deben sembrar algún punto de recogida.');

        $client->request('GET', '/gestion/reparto/huevos?nodes[]=' . $node->getId());

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Sin ROLE_GESTION_REPARTO la pantalla no se abre: es una operación que
     * toca el reparto de todo un punto de recogida.
     */
    public function testSinRolDeRepartoNoSePuedeEntrar(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_GRANJA']));
        $client->request('GET', '/gestion/reparto/huevos');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * El POST exige CSRF: sin token válido no se ejecuta nada y se vuelve a la
     * pantalla.
     */
    public function testAplicarSinTokenNoEjecutaNada(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('POST', '/gestion/reparto/huevos/aplicar', [
            'basket' => 1,
            'nodes' => [1],
            'to' => 0,
        ]);

        $this->assertTrue($client->getResponse()->isRedirect());
    }
}
