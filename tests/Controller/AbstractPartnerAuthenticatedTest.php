<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\User;
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
        $user = static::getContainer()
            ->get('doctrine')
            ->getRepository(User::class)
            ->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME);

        if ($user === null) {
            throw new \RuntimeException('Fixtures sin User socix; carga PartnerUserFixtures en db_test antes de correr los tests.');
        }

        $client->loginUser($user);

        return $client;
    }
}
