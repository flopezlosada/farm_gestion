<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Verifica que el form público de contacto deja pasar 3 envíos / hora
 * por IP y bloquea el 4º. Sin este test, el rate-limit puede romperse
 * en un futuro refactor sin que nadie se entere hasta que un bot lo
 * descubra.
 */
class ContactFormRateLimitTest extends WebTestCase
{
    public function testFourthSubmissionWithinWindowIsBlocked(): void
    {
        $client = static::createClient();

        // El sliding_window persiste entre tests en cache.app. Reseteamos
        // el limiter para la IP del test client (127.0.0.1 por default)
        // y así el test es determinista aunque se ejecute varias veces.
        /** @var RateLimiterFactory $contactLimiter */
        $contactLimiter = static::getContainer()->get('limiter.contact_form');
        $contactLimiter->create('127.0.0.1')->reset();

        // El form se embebe en base_front vía render(controller(...)), así
        // que la homepage trae el form con el _token CSRF generado.
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action="/contact"]')->form([
            'contact[name]' => 'Tester',
            'contact[email]' => 'test@example.com',
            'contact[subject]' => 'asunto de prueba',
            'contact[body]' => 'cuerpo de prueba',
        ]);

        // 3 envíos dentro del límite: cada uno redirige y muestra el flash
        // de éxito al seguir la redirección.
        for ($i = 1; $i <= 3; $i++) {
            $client->submit($form);
            self::assertResponseRedirects();
            $crawler = $client->followRedirect();
            self::assertStringContainsString(
                'se ha enviado correctamente',
                $crawler->html(),
                "Envío #$i debería pasar el rate-limit"
            );
        }

        // 4º envío: bloqueado por el limiter, flash de error en su lugar.
        $client->submit($form);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            'demasiados mensajes',
            $crawler->html(),
            'Cuarto envío en la misma ventana debería estar bloqueado'
        );
    }
}
