<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Diagnóstico de envíos (/gestion/settings/diagnostics): acceso, render,
 * previsualización de plantillas con datos reales y guardado de la redirección
 * de pruebas.
 */
class SettingsDiagnosticsControllerTest extends AbstractAuthenticatedTest
{
    /**
     * Borra los overrides persistidos para no contaminar otros tests (la
     * redirección se guarda como fila en `setting`).
     */
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testRequiereAutenticacion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/gestion/settings/diagnostics');

        $this->assertResponseRedirects();
    }

    public function testIndexRenderiza(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/settings/diagnostics');

        $this->assertResponseIsSuccessful();
    }

    /**
     * @dataProvider plantillasPrevisualizables
     */
    public function testPreviewRenderizaCadaPlantilla(string $which): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/settings/diagnostics/preview/' . $which);

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return list<array{string}>
     */
    public function plantillasPrevisualizables(): array
    {
        return [['magic_link'], ['pickup_reminder'], ['admin_summary']];
    }

    public function testPreviewDesconocidaDevuelve404(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/settings/diagnostics/preview/inexistente');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testGuardarRedireccionPersisteElAjuste(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/diagnostics');
        $token = $crawler->filter('form[action$="/redirect"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/gestion/settings/diagnostics/redirect', [
            '_csrf_token' => $token,
            'redirect_to' => 'paco@test.org',
        ]);

        $this->assertResponseRedirects('/gestion/settings/diagnostics');

        $settings = static::getContainer()->get(AppSettings::class);
        $this->assertSame('paco@test.org', $settings->getString(AppSettings::EMAIL_REDIRECT_TO));
    }

    public function testLanzarCronEnSimulacionRedirige(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/diagnostics');
        $token = $crawler->filter('form[action$="/cron"] input[name="_csrf_token"]')->first()->attr('value');

        $client->request('POST', '/gestion/settings/diagnostics/cron', [
            '_csrf_token' => $token,
            'which' => 'reminder',
            'dry_run' => '1',
        ]);

        $this->assertResponseRedirects('/gestion/settings/diagnostics');
    }
}
