<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base para tests funcionales que requieren un socix logueado en el
 * panel. Las credenciales coinciden con las que carga PartnerUserFixtures.
 */
abstract class AbstractPartnerAuthenticatedTest extends WebTestCase
{
    protected function createPartnerAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->filter('form')->form([
            '_username' => PartnerUserFixtures::USER_SOCIX_USERNAME,
            '_password' => PartnerUserFixtures::USER_SOCIX_PASSWORD,
        ]);
        $client->submit($form);
        $client->followRedirect();

        return $client;
    }
}
