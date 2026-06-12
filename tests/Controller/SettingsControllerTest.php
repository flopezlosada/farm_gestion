<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pantalla de configuración (/gestion/configuracion): render, guardado de
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
        $crawler = $client->request('GET', '/gestion/configuracion/');

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
        $crawler = $client->request('GET', '/gestion/configuracion/');

        $form = $crawler->selectButton('Guardar configuración')->form();
        $form[sprintf('settings[%s]', AppSettings::SELF_REGISTRATION)]->tick();
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/configuracion/');

        $settings = static::getContainer()->get(AppSettings::class);
        $this->assertTrue($settings->getBool(AppSettings::SELF_REGISTRATION));
        // No marcado en el form ⇒ se guarda apagado, aunque su default sea true.
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
        $crawler = $client->request('GET', '/gestion/configuracion/');

        $form = $crawler->selectButton('Guardar configuración')->form();
        $form[sprintf('settings[%s]', AppSettings::PICKUP_REMINDER_DAYS_BEFORE)]->setValue('3');
        $form[sprintf('settings[%s]', AppSettings::DEADLINE_DAYS_BEFORE)]->setValue('99'); // fuera de rango
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/configuracion/');

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
        $crawler = $client->request('GET', '/gestion/configuracion/');

        $form = $crawler->selectButton('Guardar configuración')->form();
        $form[sprintf('settings[%s][h]', AppSettings::DEADLINE_TIME)]->setValue('9');
        $form[sprintf('settings[%s][m]', AppSettings::DEADLINE_TIME)]->setValue('5');
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/configuracion/');

        $settings = static::getContainer()->get(AppSettings::class);
        $this->assertSame('09:05', $settings->getTime(AppSettings::DEADLINE_TIME));
    }
}
