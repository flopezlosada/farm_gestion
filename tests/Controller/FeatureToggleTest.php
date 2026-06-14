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
}
