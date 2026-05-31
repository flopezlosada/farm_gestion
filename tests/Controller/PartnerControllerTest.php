<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Service\Delivery\WeeklyBasketSkipper;

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

    /**
     * Happy-path funcional del saltar desde el calendario: se scrapea el token
     * CSRF real que pinta la pantalla del mes de la entrega y, al postearlo, la
     * entrega pasa a "No recoge" (estado 2). Cierra el ciclo que el test de CSRF
     * inválido dejaba abierto ahora que la UI cablea los botones.
     */
    public function testDeliveryCalendarSkipTogglesStatusWithScrapedToken(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner);

        $wb = $em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner]);
        $this->assertNotNull($wb, 'El socix de fixtures debería tener una entrega.');
        $this->assertSame(
            WeeklyBasketSkipper::STATUS_PICKS,
            $wb->getWeeklyBasketStatus()?->getId(),
            'El socix de fixtures debería partir recogiendo (estado 1).',
        );

        $basketId = $wb->getBasket()->getId();
        $date = $wb->getBasket()->getDate();

        // La pantalla del mes, con esa entrega seleccionada (?sel), pinta el panel
        // con el formulario de saltar y su token.
        $crawler = $client->request('GET', sprintf(
            '/gestion/partner/%d/calendar?year=%d&month=%d&sel=%d',
            $partner->getId(),
            (int) $date->format('Y'),
            (int) $date->format('n'),
            $basketId,
        ));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $tokenInput = $crawler->filter(sprintf('#rcal-skip-form-%d input[name="_csrf_token"]', $basketId));
        $this->assertGreaterThan(0, $tokenInput->count(), 'El calendario debería pintar el formulario de saltar con su token.');

        $client->request('POST', sprintf('/gestion/partner/%d/calendar/skip/%d', $partner->getId(), $basketId), [
            '_csrf_token' => $tokenInput->attr('value'),
        ]);
        $this->assertResponseRedirects();

        $em->clear();
        $reloaded = $em->getRepository(WeeklyBasket::class)->find($wb->getId());
        $newStatus = $reloaded->getWeeklyBasketStatus()?->getId();

        // db_test no tiene rollback transaccional: dejar la entrega saltada
        // ensuciaría re-runs y otros tests. Se restaura ANTES de la aserción
        // (que podría lanzar) para no dejar estado sucio si fallara.
        $reloaded->setWeeklyBasketStatus(
            $em->getRepository(WeeklyBasketStatus::class)->find(WeeklyBasketSkipper::STATUS_PICKS),
        );
        $em->flush();

        $this->assertSame(
            WeeklyBasketSkipper::STATUS_SKIPPED,
            $newStatus,
            'Con token válido, saltar deja la entrega en estado 2 (No recoge).',
        );
    }

    /**
     * POST de mover (cambio puntual de día desde el calendario del gestor) sin
     * token CSRF válido redirige y NO crea ningún PartnerDeliveryShift. El
     * happy-path del movimiento descansa en los tests del motor de shift
     * (DeliveryShiftApplier/Validator/Candidates), que el panel del socio ya
     * ejercita; aquí solo se cubre la guarda de seguridad del nuevo endpoint.
     */
    public function testDeliveryCalendarMoveRejectsInvalidCsrf(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner);

        $wb = $em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner]);
        $this->assertNotNull($wb, 'El socix de fixtures debería tener una entrega.');
        $basketId = $wb->getBasket()->getId();

        $client->request('POST', sprintf('/gestion/partner/%d/calendar/move/%d', $partner->getId(), $basketId), [
            '_csrf_token' => 'token-invalido',
            'to_basket_id' => $basketId,
        ]);
        $this->assertResponseRedirects();

        $em->clear();
        $shift = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner->getId(), 'fromBasket' => $basketId]);
        $this->assertNull($shift, 'Sin token CSRF válido no debe crearse ningún cambio puntual.');
    }
}
