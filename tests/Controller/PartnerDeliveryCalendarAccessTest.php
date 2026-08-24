<?php

namespace App\Tests\Controller;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Autorización del calendario de recogida por socia
 * ({@see \App\Controller\PartnerDeliveryCalendarController}).
 *
 * Es logística de REPARTO, así que lo pueden usar tanto ROLE_GESTION_REPARTO
 * como ROLE_GESTION_SOCIXS, pero no un rol ajeno. Editar la ficha de la persona
 * (PartnerController) sigue siendo solo de socixs: un reparto-puro recibe 403.
 *
 * El render del calendario y el saltar ya los cubre PartnerControllerTest (con
 * admin); aquí solo se verifica el gating por rol.
 */
class PartnerDeliveryCalendarAccessTest extends AbstractAuthenticatedTest
{
    /** @var int[] Usuarios creados, para limpiarlos al final. */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $em = static::getContainer()->get('doctrine')->getManager();
            foreach ($this->createdUserIds as $id) {
                $user = $em->getRepository(User::class)->find($id);
                if ($user !== null) {
                    $em->remove($user);
                }
            }
            $em->flush();
        }

        parent::tearDown();
    }

    public function testCalendarIsForRepartoAndSocixsNotOthers(): void
    {
        $client = static::createClient();
        $partner = static::getContainer()->get('doctrine')->getRepository(Partner::class)->findOneBy([]);
        $this->assertNotNull($partner, 'Fixtures sin partners; carga PartnerFixtures en db_test.');

        $calendar = sprintf('/gestion/partner/%d/calendar', $partner->getId());
        $edit = sprintf('/gestion/partner/%d/edit', $partner->getId());

        // Reparto: ve el calendario, pero NO edita la ficha de la persona.
        $this->loginWithRoles($client, ['ROLE_GESTION_REPARTO']);
        $client->request('GET', $calendar);
        $this->assertNotSame(403, $client->getResponse()->getStatusCode(), 'Reparto debería poder ver el calendario.');
        $client->request('GET', $edit);
        $this->assertResponseStatusCodeSame(403, 'Reparto NO debería poder editar la ficha de la persona.');

        // Socixs: sigue viendo el calendario (no se le quita nada).
        $this->loginWithRoles($client, ['ROLE_GESTION_SOCIXS']);
        $client->request('GET', $calendar);
        $this->assertNotSame(403, $client->getResponse()->getStatusCode(), 'Socixs debería poder ver el calendario.');

        // Granja (rol ajeno): ni el calendario.
        $this->loginWithRoles($client, ['ROLE_GESTION_GRANJA']);
        $client->request('GET', $calendar);
        $this->assertResponseStatusCodeSame(403, 'Un rol ajeno no debería ver el calendario de recogida.');
    }

    /**
     * Escritura del calendario tras el split de socixs: la mutación (POST) debe
     * seguir abierta a reparto y a la escritura de socixs, pero NO al solo
     * lectura de socixs. Verifica la frontera del firewall (access_control), no
     * del controller: por eso basta con distinguir 403 (cortado) de cualquier
     * otro código (dejado pasar; sin CSRF el controller redirige, pero ya no es
     * cosa del firewall).
     */
    public function testCalendarWriteOpenToRepartoAndSocixsEditNotReadOnly(): void
    {
        $client = static::createClient();
        $partner = static::getContainer()->get('doctrine')->getRepository(Partner::class)->findOneBy([]);
        $this->assertNotNull($partner, 'Fixtures sin partners; carga PartnerFixtures en db_test.');

        $reset = sprintf('/gestion/partner/%d/calendar/reset', $partner->getId());

        // Reparto: conserva la escritura del calendario (regresión del split).
        $this->loginWithRoles($client, ['ROLE_GESTION_REPARTO']);
        $client->request('POST', $reset);
        $this->assertNotSame(403, $client->getResponse()->getStatusCode(), 'Reparto debería poder mutar el calendario.');

        // Escritura de socixs: también.
        $this->loginWithRoles($client, ['ROLE_GESTION_SOCIXS_EDIT']);
        $client->request('POST', $reset);
        $this->assertNotSame(403, $client->getResponse()->getStatusCode(), 'La escritura de socixs debería poder mutar el calendario.');

        // Solo lectura de socixs: NO muta.
        $this->loginWithRoles($client, ['ROLE_GESTION_SOCIXS']);
        $client->request('POST', $reset);
        $this->assertResponseStatusCodeSame(403, 'El solo-lectura de socixs no debería mutar el calendario.');
    }

    /**
     * Quitar la cesta extra DESDE EL CALENDARIO arregla un desajuste de permisos: quien
     * gestiona reparto podía crear días con dos cestas ("trasladar sumando") pero no
     * deshacerlos, porque el único sitio para quitar una extra era la ficha del socio, que
     * exige ROLE_GESTION_SOCIXS_EDIT. Al vivir bajo `/calendar/...` hereda la excepción de
     * access_control del calendario. Misma frontera que el reset: 403 vs cualquier otro
     * código (sin CSRF el controller redirige, pero eso ya no es cosa del firewall).
     */
    public function testRemoveExtraIsOpenToRepartoNotToReadOnlySocixs(): void
    {
        $client = static::createClient();
        $doctrine = static::getContainer()->get('doctrine');
        $partner = $doctrine->getRepository(Partner::class)->findOneBy([]);
        $basket = $doctrine->getRepository(Basket::class)->findOneBy([]);
        $this->assertNotNull($partner, 'Fixtures sin partners; carga PartnerFixtures en db_test.');
        $this->assertNotNull($basket, 'Fixtures sin semanas de reparto (Basket) en db_test.');

        $removeExtra = sprintf('/gestion/partner/%d/calendar/extra/%d/remove', $partner->getId(), $basket->getId());

        $this->loginWithRoles($client, ['ROLE_GESTION_REPARTO']);
        $client->request('POST', $removeExtra);
        $this->assertNotSame(403, $client->getResponse()->getStatusCode(), 'Reparto debería poder quitar una cesta extra desde el calendario.');

        $this->loginWithRoles($client, ['ROLE_GESTION_SOCIXS_EDIT']);
        $client->request('POST', $removeExtra);
        $this->assertNotSame(403, $client->getResponse()->getStatusCode(), 'La escritura de socixs también.');

        $this->loginWithRoles($client, ['ROLE_GESTION_SOCIXS']);
        $client->request('POST', $removeExtra);
        $this->assertResponseStatusCodeSame(403, 'El solo-lectura de socixs no debería quitar una cesta extra.');
    }

    /**
     * @param string[] $roles
     */
    private function loginWithRoles(KernelBrowser $client, array $roles): void
    {
        $user = $this->createUserWithRoles($roles);
        $this->createdUserIds[] = $user->getId();
        $client->loginUser($user);
    }
}
