<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La guía de ayuda del back-office.
 *
 * Lo que se protege: que un área ligada a un módulo (encuestas, contabilidad)
 * sólo exista con el módulo encendido y, si lo pide, con su permiso —una guía de
 * algo que no está en el menú confunde—, y que las anclas a las que apuntan los
 * «?» de las pantallas sigan existiendo.
 */
class HelpControllerTest extends AbstractAuthenticatedTest
{
    use SurveyTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupSurveys();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();
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

    public function testConContabilidadApagadaNoHayAyudaDeContabilidad(): void
    {
        $client = $this->createAuthenticatedClient();

        $crawler = $client->request('GET', '/gestion/help');
        $this->assertStringNotContainsString('Contabilidad', $crawler->filter('.help-tiles')->text());

        $client->request('GET', '/gestion/help/contabilidad');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testConContabilidadEncendidaLaAyudaTieneLasAnclasDeLasPantallas(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        $crawler = $client->request('GET', '/gestion/help');
        $this->assertStringContainsString('Contabilidad', $crawler->filter('.help-tiles')->text());

        $crawler = $client->request('GET', '/gestion/help/contabilidad/fragment');
        $this->assertResponseIsSuccessful();

        // Las que usan los «?» de portada, movimientos, facturas, remesas, gestoría y presupuestos.
        foreach (['ritmo', 'movimientos', 'facturas', 'emitidas', 'remesa', 'gestoria', 'anual'] as $anchor) {
            $this->assertCount(1, $crawler->filter('#' . $anchor), sprintf('Falta la tarjeta #%s.', $anchor));
        }
    }

    /** Con el módulo encendido, quien no lleva contabilidad no ve su guía, igual que no ve su menú. */
    public function testSinPermisoDeContabilidadNoSeVeSuAyuda(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_SOCIXS']));
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        $crawler = $client->request('GET', '/gestion/help');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Contabilidad', $crawler->filter('.help-tiles')->text());

        $client->request('GET', '/gestion/help/contabilidad/fragment');
        $this->assertResponseStatusCodeSame(404);
    }
}
