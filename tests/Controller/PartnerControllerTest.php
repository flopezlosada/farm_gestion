<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;

/**
 * Smoke test del listado y la edición de socixs.
 */
class PartnerControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/partner/ con admin logueado devuelve 200.
     */
    public function testPartnerListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET del edit de un socio SIN fecha de inscripción devuelve 200.
     *
     * Regresión: el edit hacía getInscriptionDate()->format() sin comprobar
     * null, y además metía un string en el DateType del form, así que petaba
     * con 500 tanto si la fecha era null como si no. Autocontenido: crea un
     * socio sin fecha, abre su edit y lo borra.
     */
    public function testEditPartnerWithoutInscriptionDateReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = (new Partner())
            ->setname('TEST')
            ->setSurname('Sin Fecha ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $em->persist($partner);
        $em->flush();
        $partnerId = $partner->getId();

        $client->request('GET', sprintf('/gestion/partner/%d/edit', $partnerId));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->remove($em->getRepository(Partner::class)->find($partnerId));
        $em->flush();
    }

    /**
     * GET del calendario de recogida de un socio devuelve 200 (lectura v0).
     */
    public function testDeliveryCalendarReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = $em->getRepository(Partner::class)->findOneBy([]);
        $this->assertNotNull($partner, 'Fixtures sin ningún socio.');

        $client->request('GET', sprintf('/gestion/partner/%d/calendar', $partner->getId()));

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * POST de saltar sin token CSRF válido redirige y NO altera el estado de la
     * entrega (guarda de seguridad del endpoint). El toggle en sí está cubierto
     * por WeeklyBasketSkipperTest (unit) y por el panel; el happy-path funcional
     * se añadirá al cablear la UI del calendario, que dará el token a scrapear.
     */
    public function testDeliveryCalendarSkipRejectsInvalidCsrf(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner);

        $wb = $em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner]);
        $this->assertNotNull($wb, 'El socix de fixtures debería tener una entrega.');
        $statusBefore = $wb->getWeeklyBasketStatus()?->getId();

        $client->request('POST', sprintf('/gestion/partner/%d/calendar/skip/%d', $partner->getId(), $wb->getBasket()->getId()), [
            '_csrf_token' => 'token-invalido',
        ]);

        $this->assertResponseRedirects();

        $em->clear();
        $reloaded = $em->getRepository(WeeklyBasket::class)->find($wb->getId());
        $this->assertSame(
            $statusBefore,
            $reloaded->getWeeklyBasketStatus()?->getId(),
            'Sin token CSRF válido, el estado de la entrega no debe cambiar.',
        );
    }
}
