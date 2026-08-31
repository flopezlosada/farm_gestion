<?php

namespace App\Tests\Controller;

use App\Entity\CronRun;
use App\Entity\Setting;
use App\Repository\CronRunRepository;
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
        foreach ($em->getRepository(CronRun::class)->findAll() as $run) {
            $em->remove($run);
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
            // La pantalla identifica la tarea por su clave del manifiesto, no por
            // un alias propio (antes 'reminder').
            'which' => AppSettings::CRON_PICKUP_REMINDER,
            'dry_run' => '1',
        ]);

        $this->assertResponseRedirects('/gestion/settings/diagnostics');
    }

    /**
     * Esta pantalla ejecuta como lo haría el RELOJ: con la tarea pausada en
     * /gestion/settings, el botón "Ejecutar" no envía nada y la ejecución queda
     * registrada como inhibida.
     *
     * Es la diferencia deliberada con /gestion/settings, cuyo botón sí fuerza
     * porque hace de sustituto del reloj mientras está caído. Sin este test, un
     * refactor puede unificar los dos comportamientos sin que nada chille — que
     * es justo lo que pasó al extraer el runner compartido.
     */
    public function testEjecutarRespetaLaTareaPausada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::CRON_PICKUP_REMINDER, false);

        $crawler = $client->request('GET', '/gestion/settings/diagnostics');
        $token = $crawler->filter('form[action$="/cron"] input[name="_csrf_token"]')->first()->attr('value');

        $client->request('POST', '/gestion/settings/diagnostics/cron', [
            '_csrf_token' => $token,
            'which' => AppSettings::CRON_PICKUP_REMINDER,
            'dry_run' => '0',
        ]);

        $this->assertResponseRedirects('/gestion/settings/diagnostics');

        $runs = static::getContainer()->get(CronRunRepository::class)
            ->findRecentForTask(AppSettings::CRON_PICKUP_REMINDER, 1);
        $this->assertCount(1, $runs, 'La ejecución debe quedar registrada aunque esté inhibida.');
        $this->assertSame(CronRun::STATUS_DISABLED, $runs[0]->getStatus());
    }

    /**
     * Aunque esta pantalla no fuerce, quien lanza es una persona: el registro
     * debe decir "a mano". Si el origen se dedujera de --force, esta ejecución
     * se haría pasar por el reloj y la pantalla daría por vivo un planificador
     * parado.
     */
    public function testLaEjecucionQuedaRegistradaComoManualAunqueNoFuerce(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/diagnostics');
        $token = $crawler->filter('form[action$="/cron"] input[name="_csrf_token"]')->first()->attr('value');

        // El resumen a administración: su tarea viene encendida por defecto, así
        // que se ejecuta de verdad (y el toggle de email, también encendido, deja
        // pasar; sin destinatario configurado el propio comando no manda nada).
        $client->request('POST', '/gestion/settings/diagnostics/cron', [
            '_csrf_token' => $token,
            'which' => AppSettings::CRON_ADMIN_DELIVERY_SUMMARY,
            'dry_run' => '0',
        ]);

        $runs = static::getContainer()->get(CronRunRepository::class)
            ->findRecentForTask(AppSettings::CRON_ADMIN_DELIVERY_SUMMARY, 1);
        $this->assertCount(1, $runs);
        $this->assertSame(CronRun::TRIGGER_MANUAL, $runs[0]->getTriggerSource());
    }
}
