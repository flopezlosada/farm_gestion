<?php

namespace App\Tests\Controller;

use App\Entity\User;

/**
 * Modelo lectura/escritura aplicado a socixs (piloto del split por sección).
 *
 * La lectura la da ROLE_GESTION_SOCIXS; la escritura, ROLE_GESTION_SOCIXS_EDIT,
 * exigida por método HTTP en access_control sobre ^/gestion/partner. Estos
 * tests fijan esa frontera de seguridad a nivel de firewall (no de plantilla):
 * un solo-lectura ve el listado pero no puede mutar, y un _EDIT sí.
 *
 * Además se verifica que las mutaciones que el código legacy exponía por GET
 * (demote, add_family) ya NO responden a GET (405): si volvieran a aceptar GET,
 * el gateo por método dejaría un agujero para los solo-lectura.
 */
class PartnerSectionAccessTest extends AbstractAuthenticatedTest
{
    /**
     * Solo lectura: ve el listado (200), pero el firewall le corta con 403
     * cualquier escritura (toda mutación de socixs es POST/PUT/PATCH/DELETE).
     */
    public function testReadOnlyRoleCanReadButNotWrite(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_GESTION_SOCIXS']);
        $userId = $user->getId();
        $client->loginUser($user);

        $client->request('GET', '/gestion/partner/');
        $this->assertResponseIsSuccessful();

        // El alta es POST: el firewall lo rechaza antes de llegar al controller.
        $client->request('POST', '/gestion/partner/new');
        $this->assertResponseStatusCodeSame(403);

        $this->removeUser($userId);
    }

    /**
     * Escritura: el firewall deja pasar el POST (lo que pase después es cosa
     * del form, pero NO un 403 del access_control).
     */
    public function testEditRoleCanReachWriteEndpoints(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_GESTION_SOCIXS_EDIT']);
        $userId = $user->getId();
        $client->loginUser($user);

        $client->request('POST', '/gestion/partner/new');
        // Sin CSRF/datos válidos el form se re-renderiza (200); lo importante es
        // que NO es el 403 que vería un solo-lectura.
        $this->assertResponseStatusCodeSame(200);

        $this->removeUser($userId);
    }

    /**
     * Regresión del GET→POST: una mutación antes expuesta por GET ya no acepta
     * GET. Se prueba con un usuario _EDIT para descartar que el 403 del firewall
     * enmascare el resultado; el método no permitido devuelve 405.
     */
    public function testConvertedMutationsRejectGet(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_GESTION_SOCIXS_EDIT']);
        $userId = $user->getId();
        $client->loginUser($user);

        // /{id}/demote y /{p1}/{p2}/add_family ahora son POST-only.
        $client->request('GET', '/gestion/partner/1/demote');
        $this->assertResponseStatusCodeSame(405);

        $client->request('GET', '/gestion/partner/1/2/add_family');
        $this->assertResponseStatusCodeSame(405);

        $this->removeUser($userId);
    }

    private function removeUser(int $userId): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->remove($em->getRepository(User::class)->find($userId));
        $em->flush();
    }
}
