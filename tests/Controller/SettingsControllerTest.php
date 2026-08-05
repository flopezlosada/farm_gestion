<?php

namespace App\Tests\Controller;

use App\Entity\CronRun;
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
        // El registro de ejecuciones también: los tests de la pantalla siembran
        // filas a mano y las ejecuciones manuales dejan la suya.
        foreach ($em->getRepository(CronRun::class)->findAll() as $run) {
            $em->remove($run);
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
     * El destinatario del resumen a administración (STRING marcado 'general') se pinta en el
     * form general y se guarda, admitiendo varias direcciones separadas por comas. Así se
     * configura el email del digest sin tocar el cron de cdmon.
     */
    public function testSavePersistsAdminSummaryRecipient(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/settings/');

        $field = sprintf('settings[%s]', AppSettings::EMAIL_ADMIN_DELIVERY_SUMMARY_TO);
        $this->assertCount(1, $crawler->filter(sprintf('input[name="%s"]', $field)), 'Falta el campo del destinatario del resumen en el form general.');

        $form = $crawler->selectButton('Guardar configuración')->form();
        $form[$field]->setValue('csa@csavegadejarama.org, otra@csavegadejarama.org');
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/settings/');

        $settings = static::getContainer()->get(AppSettings::class);
        $this->assertSame(
            'csa@csavegadejarama.org, otra@csavegadejarama.org',
            $settings->getString(AppSettings::EMAIL_ADMIN_DELIVERY_SUMMARY_TO),
        );
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
     * EL CRITERIO DE ACEPTACIÓN del paso 1: entrando en la pantalla se ve de un
     * vistazo qué hizo cada tarea la última vez, y una tarea APAGADA se
     * distingue de una que corrió SIN TRABAJO. Hasta ahora las dos salían igual
     * (en verde y calladas), que es lo que dejó pasar dos semanas de cron caído.
     */
    public function testLaPantallaDistingueApagadaDeSinTrabajoYDeHechoConExito(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->seedRun(AppSettings::CRON_GENERATE_WEEKLY_DELIVERY, CronRun::STATUS_DONE, '-2 hours', CronRun::TRIGGER_MANUAL);
        $this->seedRun(AppSettings::CRON_ADMIN_DELIVERY_SUMMARY, CronRun::STATUS_NOTHING_TO_DO, '-3 hours');
        $this->seedRun(AppSettings::CRON_PURGE_USAGE_HITS, CronRun::STATUS_DISABLED, '-4 hours');

        $crawler = $client->request('GET', '/gestion/settings/');

        $this->assertResponseIsSuccessful();
        $text = $crawler->text();
        $this->assertStringContainsString('Hizo su trabajo', $text);
        $this->assertStringContainsString('Sin trabajo', $text, 'Corrió y no había nada que hacer: es sano, y distinto de estar apagada.');
        $this->assertStringContainsString('Apagada', $text);
        $this->assertStringContainsString('a mano', $text, 'Una ejecución manual no debe hacerse pasar por el reloj.');
        $this->assertStringContainsString('los lunes a las 06:00', $text, 'La cadencia declarada se pinta junto a la tarea.');
    }

    /**
     * Una tarea sin ninguna ejecución registrada se muestra como tal, no como
     * caída: no hay referencia desde la que medir un retraso, y una alarma el
     * primer día tras el despliegue enseña a ignorar la pantalla.
     */
    public function testLaPantallaMarcaLasTareasSinRegistroComoTales(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->clearRuns();
        $crawler = $client->request('GET', '/gestion/settings/');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Sin registro todavía', $crawler->text());
    }

    /**
     * Una tarea HABILITADA que se pasa de su plazo máximo de retraso se marca
     * fuera de plazo. El recordatorio es diario con 36 horas de margen, así que
     * una última ejecución de hace cinco días es una caída.
     */
    public function testLaPantallaMarcaFueraDePlazoLaTareaHabilitadaQueSePasaDelPlazo(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->seedRun(AppSettings::CRON_PICKUP_REMINDER, CronRun::STATUS_DONE, '-5 days');
        $crawler = $client->request('GET', '/gestion/settings/');

        $this->assertStringContainsString('Fuera de plazo', $crawler->text());
    }

    /**
     * Una tarea apagada a propósito NO se marca fuera de plazo, aunque lleve
     * meses sin correr: si no, la pantalla se llena de falsas alarmas y deja de
     * servir para lo que se hizo.
     */
    public function testUnaTareaApagadaNoSeMarcaFueraDePlazo(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->clearRuns();

        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::CRON_PICKUP_REMINDER, false);
        $this->seedRun(AppSettings::CRON_PICKUP_REMINDER, CronRun::STATUS_DISABLED, '-90 days');

        $crawler = $client->request('GET', '/gestion/settings/');

        $this->assertStringNotContainsString('Fuera de plazo', $crawler->text());
    }

    /**
     * La incoherencia que de otro modo es invisible: el recordatorio depende del
     * congelado semanal (sólo lee cestas ya congeladas), así que con el congelado
     * apagado corre en verde sin avisar a nadie. La pantalla lo dice.
     */
    public function testLaPantallaAvisaDeUnaDependenciaApagada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::CRON_GENERATE_WEEKLY_DELIVERY, false);

        $crawler = $client->request('GET', '/gestion/settings/');

        $this->assertStringContainsString('Depende de', $crawler->text());
        $this->assertStringContainsString('Congelar el listado semanal', $crawler->text());
    }

    /**
     * Vacía el registro de ejecuciones. Necesario en los tests que comprueban
     * una AUSENCIA: otras clases de la suite ejecutan tareas y dejan sus filas.
     */
    private function clearRuns(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(CronRun::class)->findAll() as $run) {
            $em->remove($run);
        }
        $em->flush();
    }

    /**
     * Siembra una ejecución ya cerrada, para poder comprobar cómo la pinta la
     * pantalla sin tener que ejecutar la tarea de verdad.
     *
     * @param string $taskKey Clave de la tarea en el manifiesto.
     * @param string $status  Uno de los CronRun::STATUS_*.
     * @param string $ago     Desplazamiento relativo, p. ej. '-5 days'.
     * @param string $trigger CronRun::TRIGGER_SCHEDULE o TRIGGER_MANUAL.
     */
    private function seedRun(string $taskKey, string $status, string $ago, string $trigger = CronRun::TRIGGER_SCHEDULE): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $startedAt = (new \DateTimeImmutable())->modify($ago);

        $run = (new CronRun())
            ->setTaskKey($taskKey)
            ->setCommand(AppSettings::CRONS[$taskKey]['command'])
            ->setStatus($status)
            ->setTriggerSource($trigger)
            ->setStartedAt($startedAt)
            ->setFinishedAt($startedAt->modify('+3 seconds'))
            ->setExitCode(0);

        $em->persist($run);
        $em->flush();
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
