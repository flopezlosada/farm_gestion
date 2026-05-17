<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests de las pantallas públicas del flujo magic-link:
 * primer acceso y recuperación. Solo verifican que renderizan
 * con 200 (no disparan el envío real de emails, que requeriría
 * stub de mailer y validación de tokens).
 */
class MagicLinkPagesTest extends WebTestCase
{
    public function testFirstAccessPageReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login/first-access');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testForgotPageReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login/forgot');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
