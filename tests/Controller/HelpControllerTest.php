<?php

namespace App\Tests\Controller;

/**
 * La guía de ayuda del back-office.
 *
 * Lo que se protege: que un área ligada a un módulo (encuestas) sólo exista
 * con el módulo encendido —una guía de algo que no está en el menú confunde—,
 * y que las anclas a las que apuntan los «?» de las pantallas sigan existiendo.
 */
class HelpControllerTest extends AbstractAuthenticatedTest
{
    use SurveyTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupSurveys();
        parent::tearDown();
    }

    public function testConEncuestasApagadasNoHayAyudaDeEncuestas(): void
    {
        $client = $this->createAuthenticatedClient();

        $crawler = $client->request('GET', '/gestion/help');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Encuestas', $crawler->filter('.help-tiles')->text());

        $client->request('GET', '/gestion/help/encuestas');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/gestion/help/encuestas/fragment');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testConEncuestasEncendidasLaAyudaTieneLasAnclasDeLasPantallas(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();

        $crawler = $client->request('GET', '/gestion/help');
        $this->assertStringContainsString('Encuestas', $crawler->filter('.help-tiles')->text());

        $crawler = $client->request('GET', '/gestion/help/encuestas/fragment');
        $this->assertResponseIsSuccessful();

        // Las que usan los «?» de listado, formulario, ficha y resultados.
        foreach (['crear', 'tipos', 'abrir', 'reenviar', 'resultados', 'cerrar'] as $anchor) {
            $this->assertCount(1, $crawler->filter('#' . $anchor), sprintf('Falta la tarjeta #%s.', $anchor));
        }
    }

    public function testLasAreasDeSiempreSiguenAbiertas(): void
    {
        $client = $this->createAuthenticatedClient();

        foreach (['socios', 'reparto', 'cosechas'] as $section) {
            $client->request('GET', '/gestion/help/' . $section);
            $this->assertResponseIsSuccessful(sprintf('La ayuda de %s tiene que seguir abierta.', $section));
        }
    }
}
