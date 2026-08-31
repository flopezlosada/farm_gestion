<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Feature-flags gobernados por {@see \App\Security\FeatureVoter}: con el toggle
 * apagado (el default del catálogo) las rutas responden 403; encendido, vuelven
 * a estar accesibles. El acceso de socixs (FEATURE_PARTNER_LOGIN) se prueba
 * aparte en {@see \App\Tests\Security\UserCheckerTest}: vive en el UserChecker,
 * que loginUser() no ejercita.
 */
class FeatureToggleTest extends WebTestCase
{
    /**
     * Limpia los overrides de configuración para no contaminar otros tests, que
     * cuentan con los defaults del catálogo.
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

    private function clientLoggedAs(string $identifier): KernelBrowser
    {
        $client = static::createClient();
        $user = static::getContainer()
            ->get('doctrine')
            ->getRepository(User::class)
            ->loadUserByIdentifier($identifier);

        if ($user === null) {
            throw new \RuntimeException(sprintf('Fixtures sin User "%s".', $identifier));
        }

        $client->loginUser($user);

        return $client;
    }

    private function settings(): AppSettings
    {
        return static::getContainer()->get(AppSettings::class);
    }

    public function testEncuestasDanForbiddenEnGestionConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs('admin');
        $client->request('GET', '/gestion/surveys/');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testEncuestasAccesiblesEnGestionConElToggleEncendido(): void
    {
        $client = $this->clientLoggedAs('admin');
        $this->settings()->setBool(AppSettings::FEATURE_SURVEYS, true);

        $client->request('GET', '/gestion/surveys/');

        $this->assertResponseIsSuccessful();
    }

    public function testEncuestasDanForbiddenEnElPanelConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $client->request('GET', '/panel/surveys');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAutoservicioDaForbiddenConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        // El 403 lo emite el FeatureVoter antes de entrar al controller, así que
        // el basketId y el token CSRF son irrelevantes: nunca se llega a evaluarlos.
        $client->request('POST', '/panel/calendar/skip/1', ['_csrf_token' => 'irrelevante']);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAutoservicioAccesibleConElToggleEncendido(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $this->settings()->setBool(AppSettings::FEATURE_PARTNER_SELFSERVICE, true);

        // Con el toggle encendido la acción ya NO la corta el voter: pasa al
        // controller (que con un basketId inexistente acabará en 404, o en un
        // redirect si el gating del panel desvía). Cualquier código ≠ 403
        // demuestra que el feature-flag dejó de bloquear; afirmar el código
        // exacto ataría el test al gating interno del panel.
        $client->request('POST', '/panel/calendar/skip/999999', ['_csrf_token' => 'irrelevante']);

        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    public function testVoluntariadoDaForbiddenEnGestionConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs('admin');
        $client->request('GET', '/gestion/voluntariado');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testVoluntariadoAccesibleEnGestionConElToggleEncendido(): void
    {
        $client = $this->clientLoggedAs('admin');
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $client->request('GET', '/gestion/voluntariado');

        $this->assertResponseIsSuccessful();
    }

    public function testVoluntariadoDaForbiddenEnElPanelConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $client->request('GET', '/panel/voluntariado');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testVoluntariadoAccesibleEnElPanelConElToggleEncendido(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $client->request('GET', '/panel/voluntariado');

        $this->assertResponseIsSuccessful();
    }

    public function testGrupoDeConsumoDaForbiddenEnGestionConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs('admin');
        $client->request('GET', '/gestion/consumer-group/');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testGrupoDeConsumoAccesibleEnGestionConElToggleEncendido(): void
    {
        $client = $this->clientLoggedAs('admin');
        $this->settings()->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);

        $client->request('GET', '/gestion/consumer-group/');

        $this->assertResponseIsSuccessful();
    }

    /**
     * El catálogo (productores y sus productos) cuelga del mismo prefijo pero es
     * otro controller: se comprueba aparte para que el gateo no dependa de que
     * alguien recuerde repetir el atributo al añadir pantallas al módulo.
     */
    public function testProductoresDanForbiddenConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs('admin');
        $client->request('GET', '/gestion/consumer-group/producers/');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testGrupoDeConsumoDaForbiddenEnElPanelConElToggleApagado(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $client->request('GET', '/panel/consumer-group');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testGrupoDeConsumoAccesibleEnElPanelConElToggleEncendido(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $this->settings()->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);

        $client->request('GET', '/panel/consumer-group');

        $this->assertResponseIsSuccessful();
    }

    /**
     * El grupo de consumo se asoma a una pantalla que no es suya: el calendario
     * del socix pinta las entregas del pedido junto a las de la cesta. Apagado,
     * el controller no debe ni consultarlas y el calendario tiene que cargar
     * exactamente igual.
     */
    public function testElCalendarioDelPanelSigueCargandoConElGrupoDeConsumoApagado(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $client->request('GET', '/panel/calendar');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Y encendido también: aquí sí se ejecuta la consulta de entregas y se pinta
     * el panel del pedido, así que este test es además el que ejercita de verdad
     * el esquema nuevo del módulo contra la base de test.
     */
    public function testElCalendarioDelPanelSigueCargandoConElGrupoDeConsumoEncendido(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $this->settings()->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);

        $client->request('GET', '/panel/calendar');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Con el módulo apagado, la home del panel sigue funcionando igual: el
     * bloque de voluntariado no debe poder tumbarla. Es la pantalla que más se
     * usa y la que más caro sale romper.
     */
    public function testLaHomeDelPanelSigueCargandoConElVoluntariadoApagado(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $client->request('GET', '/panel');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Y con el módulo encendido, también: aquí sí se consulta la lista de
     * tareas y se pinta el bloque.
     */
    public function testLaHomeDelPanelSigueCargandoConElVoluntariadoEncendido(): void
    {
        $client = $this->clientLoggedAs(PartnerUserFixtures::USER_SOCIX_USERNAME);
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $client->request('GET', '/panel');

        $this->assertResponseIsSuccessful();
    }
}
