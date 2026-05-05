<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base para tests funcionales que requieren un admin logueado.
 *
 * Centraliza el flujo de login para que los tests sólo se ocupen de la
 * pantalla bajo prueba.
 */
abstract class AbstractAuthenticatedTest extends WebTestCase
{
    /**
     * Crea un cliente HTTP simulado con la sesión iniciada como admin/admin.
     *
     * Las credenciales coinciden con las que carga UserFixtures, así que
     * cualquier test depende de que las fixtures estén cargadas en db_test.
     *
     * @return KernelBrowser
     */
    protected function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->filter('form')->form([
            '_username' => 'admin',
            '_password' => 'admin',
        ]);
        $client->submit($form);
        $client->followRedirect();

        return $client;
    }
}
