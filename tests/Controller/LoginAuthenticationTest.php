<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifica el flujo de login con credenciales válidas.
 *
 * Usa admin/admin tal como las carga UserFixtures.
 */
class LoginAuthenticationTest extends WebTestCase
{
    /**
     * POST a /login con admin/admin entra y, tras el dispatcher /post-login,
     * acaba en /gestion/dashboard (ROLE_ADMIN). Seguimos la cadena entera
     * porque el primer redirect apunta al dispatcher, no al destino final.
     */
    public function testLoginWithValidCredentialsRedirectsToDashboard(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->filter('form')->form([
            '_username' => 'admin',
            '_password' => 'admin',
        ]);
        $client->submit($form);

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('/post-login', (string) $client->getResponse()->headers->get('Location'));

        $client->followRedirect();
        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('/gestion/dashboard', (string) $client->getResponse()->headers->get('Location'));
    }
}
