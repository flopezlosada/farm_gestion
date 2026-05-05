<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test smoke: la página de login pública responde 200.
 *
 * Sirve para verificar que la cadena phpunit → kernel test → BBDD db_test
 * está bien configurada antes de escribir tests más ambiciosos.
 */
class LoginPageTest extends WebTestCase
{
    /**
     * GET /login en estado anónimo debe devolver 200.
     */
    public function testLoginPageReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
