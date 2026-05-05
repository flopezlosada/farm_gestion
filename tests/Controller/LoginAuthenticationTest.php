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
     * POST a /login_check con admin/admin redirige al dashboard.
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
        $this->assertStringContainsString('/gestion/dashboard', (string) $client->getResponse()->headers->get('Location'));
    }
}
