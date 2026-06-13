<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests de las pantallas públicas del flujo magic-link (primer acceso y
 * recuperación) y del guard de envío: con el acceso de socixs cerrado por
 * configuración, "recuperar acceso" no manda enlace a un socix (camino
 * antifuga), igual que primer acceso. La regla de quién puede entrar vive en
 * {@see \App\Security\UserChecker} (ver {@see \App\Tests\Security\UserCheckerTest});
 * aquí se comprueba que el controller la respeta antes de enviar.
 */
class MagicLinkPagesTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

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

    /**
     * Con el acceso de socixs cerrado (default), "recuperar acceso" sigue el
     * camino antifuga (redirige a /login/sent) pero NO envía el enlace a un socix.
     */
    public function testForgotNoMandaEnlaceASocixConElAccesoCerrado(): void
    {
        $client = static::createClient();
        $client->request('POST', '/login/forgot', $this->forgotPayload($client, PartnerUserFixtures::USER_SOCIX_EMAIL));

        $this->assertResponseRedirects('/login/sent');
        $this->assertEmailCount(0);
    }

    /**
     * Con el acceso de socixs abierto, "recuperar acceso" sí manda el enlace.
     */
    public function testForgotMandaEnlaceASocixConElAccesoAbierto(): void
    {
        $client = static::createClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_PARTNER_LOGIN, true);

        $client->request('POST', '/login/forgot', $this->forgotPayload($client, PartnerUserFixtures::USER_SOCIX_EMAIL));

        $this->assertResponseRedirects('/login/sent');
        $this->assertEmailCount(1);
    }

    /**
     * Arma el POST de recuperación con un token CSRF válido tomado del propio
     * formulario (mismo cliente ⇒ misma sesión).
     */
    private function forgotPayload(KernelBrowser $client, string $email): array
    {
        $crawler = $client->request('GET', '/login/forgot');

        return [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'email' => $email,
        ];
    }
}
