<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pantalla de configuración (/gestion/settings): render, guardado de
 * toggles y aplicación de defaults cuando no hay override.
 */
class SettingsControllerTest extends AbstractAuthenticatedTest
{
    /**
     * Limpia los overrides persistidos para no contaminar otros tests: sin
     * filas en `setting`, todo vuelve a los defaults del catálogo.
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

    /**
     * La pantalla renderiza con todos los ajustes del catálogo.
     */
    public function testIndexRendersAllCatalogSettings(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        $this->assertResponseIsSuccessful();
        foreach (array_merge(array_keys(AppSettings::BOOLEANS), array_keys(AppSettings::INTEGERS)) as $name) {
            $this->assertCount(
                1,
                $crawler->filter(sprintf('input[name="settings[%s]"]', $name)),
                sprintf('Falta el ajuste "%s" en la pantalla.', $name)
            );
        }
        // La hora se edita con dos campos (hora : minutos), uno por parte.
        foreach (array_keys(AppSettings::TIMES) as $name) {
            $this->assertCount(1, $crawler->filter(sprintf('input[name="settings[%s][h]"]', $name)));
            $this->assertCount(1, $crawler->filter(sprintf('input[name="settings[%s][m]"]', $name)));
        }
    }

    /**
     * Guardar con un toggle marcado lo persiste; al recargar, la pantalla lo
     * muestra encendido y AppSettings lo lee como true.
     */
    public function testSavePersistsToggles(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        $form = $crawler->selectButton('Guardar configuración')->form();
        $form[sprintf('settings[%s]', AppSettings::SELF_REGISTRATION)]->tick();
        // Su default es true, así que viene marcado del HTML; lo desmarcamos
        // para comprobar que, ausente del POST, el controller lo apaga.
        $form[sprintf('settings[%s]', AppSettings::EMAIL_ADMIN_DELIVERY_SUMMARY)]->untick();
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/settings/');

        $settings = static::getContainer()->get(AppSettings::class);
        $this->assertTrue($settings->getBool(AppSettings::SELF_REGISTRATION));
        // Desmarcado en el form ⇒ se guarda apagado, aunque su default sea true.
        $this->assertFalse($settings->getBool(AppSettings::EMAIL_ADMIN_DELIVERY_SUMMARY));
    }

    /**
     * Sin override persistido mandan los defaults del catálogo: alta cerrada,
     * recordatorio apagado, resumen a admin encendido.
     */
    public function testDefaultsApplyWithoutOverrides(): void
    {
        $settings = static::getContainer()->get(AppSettings::class);

        $this->assertFalse($settings->getBool(AppSettings::SELF_REGISTRATION));
        $this->assertFalse($settings->getBool(AppSettings::EMAIL_PICKUP_REMINDER));
        $this->assertTrue($settings->getBool(AppSettings::EMAIL_ADMIN_DELIVERY_SUMMARY));
    }

    /**
     * Los enteros y la hora sin override devuelven el default del catálogo.
     */
    public function testNumericAndTimeDefaultsApplyWithoutOverrides(): void
    {
        $settings = static::getContainer()->get(AppSettings::class);

        $this->assertSame(2, $settings->getInt(AppSettings::PICKUP_REMINDER_DAYS_BEFORE));
        $this->assertSame(1, $settings->getInt(AppSettings::DEADLINE_DAYS_BEFORE));
        $this->assertSame('23:59', $settings->getTime(AppSettings::DEADLINE_TIME));
    }

    /**
     * Guardar un entero lo persiste, y un valor fuera de rango se recorta al
     * máximo del catálogo (la antelación de cierre va de 0 a 7 días).
     */
    public function testSavePersistsAndClampsIntegers(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        $form = $crawler->selectButton('Guardar configuración')->form();
        $form[sprintf('settings[%s]', AppSettings::PICKUP_REMINDER_DAYS_BEFORE)]->setValue('3');
        $form[sprintf('settings[%s]', AppSettings::DEADLINE_DAYS_BEFORE)]->setValue('99'); // fuera de rango
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/settings/');

        $settings = static::getContainer()->get(AppSettings::class);
        $this->assertSame(3, $settings->getInt(AppSettings::PICKUP_REMINDER_DAYS_BEFORE));
        $this->assertSame(7, $settings->getInt(AppSettings::DEADLINE_DAYS_BEFORE), 'La antelación se recorta al máximo (7).');
    }

    /**
     * setTime persiste el "HH:MM"; un valor con formato inválido cae al
     * default (23:59), nunca queda una hora corrupta en BBDD.
     */
    public function testSetTimePersistsAndFallsBackOnInvalid(): void
    {
        $settings = static::getContainer()->get(AppSettings::class);

        $settings->setTime(AppSettings::DEADLINE_TIME, '20:00');
        $this->assertSame('20:00', $settings->getTime(AppSettings::DEADLINE_TIME));

        $settings->setTime(AppSettings::DEADLINE_TIME, '99:99'); // inválido
        $this->assertSame('23:59', $settings->getTime(AppSettings::DEADLINE_TIME), 'Una hora inválida cae al default.');
    }

    /**
     * Los dos campos de hora (hora : minutos) se combinan y normalizan a
     * "HH:MM" con dos dígitos al guardar el formulario, aunque lleguen sin el
     * cero de relleno.
     */
    public function testSaveCombinesTimeFieldsIntoValue(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        $form = $crawler->selectButton('Guardar configuración')->form();
        $form[sprintf('settings[%s][h]', AppSettings::DEADLINE_TIME)]->setValue('9');
        $form[sprintf('settings[%s][m]', AppSettings::DEADLINE_TIME)]->setValue('5');
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/settings/');

        $settings = static::getContainer()->get(AppSettings::class);
        $this->assertSame('09:05', $settings->getTime(AppSettings::DEADLINE_TIME));
    }

    /**
     * La sección "Tareas programadas" expone, por cada cron, su formulario de
     * ejecución manual (y el de previsualización en los que la ofrecen).
     */
    public function testCronSectionExposesRunForms(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        $this->assertResponseIsSuccessful();
        // Cada cron tiene su form de ejecución real.
        $this->assertCount(1, $crawler->filter('#cronrun-cron_generate_weekly_delivery'));
        $this->assertCount(1, $crawler->filter('#cronrun-cron_purge_usage_hits'));
        // Los de email ofrecen además previsualización (dry-run).
        $this->assertCount(1, $crawler->filter('#crondry-cron_pickup_reminder'));
        $this->assertCount(1, $crawler->filter('#crondry-cron_admin_delivery_summary'));
        // Los de mantenimiento NO ofrecen previsualización.
        $this->assertCount(0, $crawler->filter('#crondry-cron_purge_usage_hits'));
    }

    /**
     * Lanzar a mano un cron de mantenimiento (la purga, idempotente y sin
     * email) lo ejecuta en proceso, redirige y muestra la salida del comando.
     */
    public function testManualCronRunExecutesAndShowsOutput(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        // El form lleva su token CSRF y los campos ocultos (cron + mode=run).
        $form = $crawler->filter('#cronrun-cron_purge_usage_hits')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/settings/');
        $crawler = $client->followRedirect();
        $this->assertStringContainsString('Resultado:', $crawler->text());
    }

    /**
     * Un cron desconocido en el POST se rechaza (lista blanca {@see AppSettings::CRONS})
     * sin ejecutar nada.
     */
    public function testManualCronRunRejectsUnknownTask(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        // Token válido tomado de un form ya renderizado (misma sesión).
        $token = $crawler->filter('#cronrun-cron_purge_usage_hits input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/gestion/settings/cron/run', [
            '_csrf_token' => $token,
            'cron' => 'cron.no_existe',
            'mode' => 'run',
        ]);

        $this->assertResponseRedirects('/gestion/settings/');
        $crawler = $client->followRedirect();
        $this->assertStringContainsString('Tarea desconocida', $crawler->text());
    }
}
