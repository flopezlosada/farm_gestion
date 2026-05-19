<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base para tests funcionales que requieren un admin logueado.
 *
 * Centraliza el flujo de login para que los tests sólo se ocupen de la
 * pantalla bajo prueba.
 */
abstract class AbstractAuthenticatedTest extends WebTestCase
{
    /**
     * Crea un cliente HTTP simulado con la sesión iniciada como admin.
     *
     * Usa KernelBrowser::loginUser() para evitar el form-submit con CSRF:
     * los tests funcionales no necesitan ejercitar el flujo de login real
     * (eso lo cubre LoginAuthenticationTest) y el shortcut nativo es más
     * robusto que armar el POST a mano.
     *
     * Las credenciales coinciden con las que carga UserFixtures, así que
     * cualquier test depende de que las fixtures estén cargadas en db_test.
     *
     * @return KernelBrowser
     */
    protected function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $user = static::getContainer()
            ->get('doctrine')
            ->getRepository(User::class)
            ->loadUserByIdentifier('admin');

        if ($user === null) {
            throw new \RuntimeException('Fixtures sin User admin; carga UserFixtures en db_test antes de correr los tests.');
        }

        $client->loginUser($user);

        return $client;
    }
}
