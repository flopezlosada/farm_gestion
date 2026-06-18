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

    /**
     * Crea y persiste un usuario del equipo con los roles dados, para probar
     * el acceso por rol sin depender del admin de las fixtures. Requiere un
     * kernel arrancado (llama antes a {@see static::createClient()}).
     *
     * enabled=true y passwordSet=true para que ni el UserChecker (bloqueo) ni
     * el ForcePasswordChangeSubscriber (cambio forzado) interfieran.
     *
     * @param string[] $roles
     */
    protected function createUserWithRoles(array $roles): User
    {
        $suffix = uniqid();
        $user = (new User())
            ->setUsername('test-user-' . $suffix)
            ->setEmail('test-user-' . $suffix . '@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles($roles);

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
