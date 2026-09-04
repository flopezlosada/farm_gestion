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
    /** Teléfono del socix de {@see PartnerUserFixtures}, tal como está en su ficha. */
    private const SOCIX_PHONE = '600000000';

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
        $this->resetMagicLinkLimiter();

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
        $this->resetMagicLinkLimiter();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_PARTNER_LOGIN, true);

        $client->request('POST', '/login/forgot', $this->forgotPayload($client, PartnerUserFixtures::USER_SOCIX_EMAIL));

        $this->assertResponseRedirects('/login/sent');
        $this->assertEmailCount(1);
    }

    /**
     * Con el acceso de socixs abierto, el primer acceso manda el enlace cuando
     * el email y el teléfono coinciden con la ficha del socix.
     */
    public function testPrimerAccesoMandaEnlaceConEmailYTelefonoCorrectos(): void
    {
        $client = static::createClient();
        $this->resetMagicLinkLimiter();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_PARTNER_LOGIN, true);

        $client->request('POST', '/login/first-access', $this->firstAccessPayload(
            $client,
            PartnerUserFixtures::USER_SOCIX_EMAIL,
            self::SOCIX_PHONE,
        ));

        $this->assertResponseRedirects('/login/sent');
        $this->assertEmailCount(1);
    }

    /**
     * El teléfono forma parte de la identificación: si no coincide con el de la
     * ficha no se envía nada, aunque el email sí sea el de un socix real. La
     * pantalla dice lo mismo en ambos casos (antifuga), de modo que este camino
     * es la causa más probable de un "he pedido el enlace y no me llega".
     */
    public function testPrimerAccesoNoMandaEnlaceSiElTelefonoNoCoincide(): void
    {
        $client = static::createClient();
        $this->resetMagicLinkLimiter();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_PARTNER_LOGIN, true);

        $client->request('POST', '/login/first-access', $this->firstAccessPayload(
            $client,
            PartnerUserFixtures::USER_SOCIX_EMAIL,
            '699999999',
        ));

        $this->assertResponseRedirects('/login/sent');
        $this->assertEmailCount(0);
    }

    /**
     * Devuelve al limitador de enlaces de acceso su cupo entero para la IP del
     * cliente de test.
     *
     * Su ventana deslizante vive en la caché de test y persiste entre casos y
     * entre ejecuciones: sin esto, la suite acabaría fallando por agotamiento
     * del límite y no por el código bajo prueba. Se resetea en vez de subir el
     * límite en la configuración de test, que dejaría sin efecto los límites
     * reales —{@see ContactFormRateLimitTest} comprueba justamente uno.
     */
    private function resetMagicLinkLimiter(): void
    {
        static::getContainer()->get('limiter.magic_link')->create('127.0.0.1')->reset();
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

    /**
     * Arma el POST de primer acceso con un token CSRF válido tomado del propio
     * formulario (mismo cliente ⇒ misma sesión).
     */
    private function firstAccessPayload(KernelBrowser $client, string $email, string $phone): array
    {
        $crawler = $client->request('GET', '/login/first-access');

        return [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'email' => $email,
            'phone' => $phone,
        ];
    }
}
