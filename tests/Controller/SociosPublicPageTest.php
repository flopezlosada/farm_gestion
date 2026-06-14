<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La página pública "Hazte socix" (/socio) es accesible en anónimo y pinta
 * los horarios de recogida desde Node.schedule en vez de hardcodearlos
 * (punto 8 de la reunión de admin del 2026-06-11).
 */
class SociosPublicPageTest extends WebTestCase
{
    /**
     * GET /socio en anónimo devuelve 200 con el formulario de contacto
     * embebido (el protagonista de la página tras el rediseño).
     */
    public function testSociosPageIsPublicAndShowsContactForm(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/socio');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertGreaterThan(
            0,
            $crawler->filter('#pide-informacion input[name="contact[name]"]')->count(),
            'El hero debería contener el formulario de contacto embebido.'
        );
    }

    /**
     * Los horarios de los puntos de recogida salen de Node.schedule
     * (sembrados por NodeFixtures para los 3 nodos).
     */
    public function testSociosPageRendersNodeSchedulesFromDatabase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/socio');

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Viernes de 18:00 a 20:00', $content);
        $this->assertStringContainsString('Miércoles de 18:00 a cierre', $content);
        $this->assertStringContainsString('Miércoles de 18:00 a 20:00', $content);
    }
}
