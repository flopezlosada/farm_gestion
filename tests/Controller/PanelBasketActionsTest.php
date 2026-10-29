<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerNodeOverride;
use App\Entity\Setting;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Service\AppSettings;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyBasketSkipper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Tests del autoservicio del socix sobre su calendario de recogida
 * (/panel/calendar). El socix puede saltar ("no recoge") y mover una entrega;
 * son las rutas VIVAS panel_calendar_skip / panel_calendar_move, que comparten
 * el partial partner/_calendar.html.twig con el calendario del gestor.
 *
 * Reescrito desde la versión que probaba los endpoints /cesta/skip-toggle,
 * /cesta/cambiar-viernes y /cesta/cancelar-cambio-viernes: aquéllos quedaron
 * huérfanos al migrar el panel al calendario y los tests fallaban porque
 * /panel/cesta hoy solo redirige al calendario.
 */
class PanelBasketActionsTest extends AbstractPartnerAuthenticatedTest
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * Resetea el estado mutable del socix (WB status, shifts, events) para
     * que cada test arranque con la misma foto que dejan las fixtures —
     * sin recargarlas, que sería lento. Se invoca al inicio de cada test
     * con el cliente ya creado.
     */
    private function resetSocixState(KernelBrowser $client): void
    {
        $conn = $this->em($client)->getConnection();
        $params = ['email' => PartnerUserFixtures::USER_SOCIX_EMAIL];

        $conn->executeStatement(
            'UPDATE weekly_basket wb
                INNER JOIN partner p ON p.id = wb.partner_id
                SET wb.weekly_basket_status_id = 1
                WHERE p.email = :email',
            $params,
        );
        $conn->executeStatement(
            'DELETE pds FROM partner_delivery_shift pds
                INNER JOIN partner p ON p.id = pds.partner_id
                WHERE p.email = :email',
            $params,
        );
        $conn->executeStatement(
            'DELETE pe FROM partner_event pe
                INNER JOIN partner p ON p.id = pe.partner_id
                WHERE p.email = :email',
            $params,
        );
        $conn->executeStatement(
            'DELETE pno FROM partner_node_override pno
                INNER JOIN partner p ON p.id = pno.partner_id
                WHERE p.email = :email',
            $params,
        );

        // El autoservicio del socix vive tras el feature-flag FEATURE_PARTNER_SELFSERVICE,
        // apagado por defecto. Estos tests ejercitan justamente esas acciones (saltar,
        // mover, cambiar de nodo), así que lo encendemos; el tearDown limpia el override.
        $client->getContainer()->get(AppSettings::class)
            ->setBool(AppSettings::FEATURE_PARTNER_SELFSERVICE, true);
    }

    /**
     * Limpia los overrides de configuración persistidos (el feature-flag que
     * encendemos en resetSocixState) para no contaminar otros tests, que
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

    private function reloadSocixPartner(EntityManagerInterface $em): Partner
    {
        return $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
    }

    /**
     * Entrega del socix más adelantada en el tiempo: la candidata con más
     * margen para caer dentro del plazo de autoservicio (jueves previo).
     */
    private function farthestSocixWeeklyBasket(EntityManagerInterface $em, Partner $partner): ?WeeklyBasket
    {
        return $em->getRepository(WeeklyBasket::class)->createQueryBuilder('wb')
            ->innerJoin('wb.basket', 'b')
            ->where('wb.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('b.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Cierra el plazo de autoservicio de TODAS las semanas a la vista, sin depender de
     * las fechas de las fixtures: una antelación de diez años deja cualquier entrega
     * futura fuera de plazo pero lejos de su «día anterior» —justo el hueco en el que
     * las acciones del calendario se saltaban el plazo—. La fila se escribe a mano
     * porque AppSettings::setInt la recortaría a su máximo (7); al leerla no se recorta.
     * El tearDown la borra con el resto de ajustes.
     */
    private function closeEveryDeadline(KernelBrowser $client): void
    {
        $em = $this->em($client);
        $em->persist((new Setting())
            ->setName(AppSettings::DEADLINE_DAYS_BEFORE)
            ->setValue('3650'));
        $em->flush();
    }

    /**
     * Token CSRF de mover (lo comparten mover, recuperar y deshacer el traslado), leído
     * del calendario del socix como lo lee su JavaScript.
     */
    private function moveCsrfToken(KernelBrowser $client, Basket $basket): string
    {
        $client->request('GET', sprintf(
            '/panel/calendar?year=%d&month=%d&sel=%d',
            (int) $basket->getDate()->format('Y'),
            (int) $basket->getDate()->format('n'),
            $basket->getId(),
        ));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertMatchesRegularExpression('/const MOVE_CSRF = "([^"]+)";/', (string) $client->getResponse()->getContent());
        preg_match('/const MOVE_CSRF = "([^"]+)";/', (string) $client->getResponse()->getContent(), $match);

        return $match[1];
    }

    /**
     * Mensajes flash de un tipo que dejó la última petición.
     *
     * @return list<string>
     */
    private function flashes(KernelBrowser $client, string $type): array
    {
        return $client->getRequest()->getSession()->getFlashBag()->peek($type);
    }

    /**
     * Saltar desde el calendario del socix LIBERA el día: el WeeklyBasket se
     * retira (no queda clavado en estado 2) y deja un intent "no recoge" durable
     * sin destino, recuperable. Mismo contrato que el calendario del gestor
     * (testDeliveryCalendarSkipFreesTheDayAndLeavesIntent), aquí por el socix.
     */
    public function testCalendarSkipLiberaElDiaYDejaIntent(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb, 'El socix de fixtures debería tener al menos una entrega.');
        $this->assertSame(
            WeeklyBasketSkipper::STATUS_PICKS,
            $wb->getWeeklyBasketStatus()?->getId(),
            'Tras el reset el socix parte recogiendo (estado 1).',
        );

        $wbId = $wb->getId();
        $basketId = $wb->getBasket()->getId();
        $date = $wb->getBasket()->getDate();

        // La pantalla del mes, con la entrega seleccionada (?sel), pinta el panel
        // con el formulario de saltar y su token — pero SOLO si está dentro del
        // plazo de autoservicio. Si no, el socix no puede actuar: lo saltamos.
        $crawler = $client->request('GET', sprintf(
            '/panel/calendar?year=%d&month=%d&sel=%d',
            (int) $date->format('Y'),
            (int) $date->format('n'),
            $basketId,
        ));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $tokenInput = $crawler->filter(sprintf('#rcal-skip-form-%d input[name="_csrf_token"]', $basketId));
        if ($tokenInput->count() === 0) {
            $this->markTestSkipped('Ninguna entrega del socix cae dentro del plazo de autoservicio en la fecha actual.');
        }

        $client->request('POST', sprintf('/panel/calendar/skip/%d', $basketId), [
            '_csrf_token' => $tokenInput->attr('value'),
        ]);
        $this->assertResponseRedirects();

        // El EM a usar es el del contenedor del último kernel (el mismo que
        // inyectan los servicios del cleanup); con el capturado antes, las
        // entidades llegarían DETACHED al applier.
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        $basket = $em->getRepository(Basket::class)->find($basketId);
        $partner = $this->reloadSocixPartner($em);
        $wbAfter = $em->getRepository(WeeklyBasket::class)->find($wbId);
        $intent = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner->getId(), 'fromBasket' => $basketId]);

        $dayFreed = $wbAfter === null;
        // isParked, no isSkip: un "no recoge" deja la cesta PENDIENTE (papelera). Si saliera
        // marcada como trasladada sumando, isSkip seguiría siendo true y la aserción no
        // notaría la diferencia.
        $intentIsSkip = $intent !== null && $intent->isParked();

        // db_test no tiene rollback transaccional: restauramos ANTES de las
        // aserciones (que podrían lanzar) recorriendo "volver a recoger".
        if ($intent !== null) {
            $client->getContainer()->get(DeliveryShiftApplier::class)->cancelSkipIntent($intent, 'test:cleanup');
        }
        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share !== null) {
            $client->getContainer()->get(WeeklyBasketGenerator::class)->materializeShareDelivery($basket, $share);
        }

        $this->assertTrue($dayFreed, 'Saltar debe LIBERAR el día: el WeeklyBasket se retira, no se queda en estado 2.');
        $this->assertTrue($intentIsSkip, 'Saltar debe dejar un intent "no recoge" durable (sin destino).');
    }

    /**
     * POST de saltar sin token CSRF válido redirige y NO altera el estado de la
     * entrega. La guarda CSRF se evalúa antes que el plazo, así que es
     * determinista (no depende de la fecha).
     */
    public function testCalendarSkipRechazaCsrfInvalido(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb);
        $wbId = $wb->getId();
        $statusBefore = $wb->getWeeklyBasketStatus()?->getId();

        $client->request('POST', sprintf('/panel/calendar/skip/%d', $wb->getBasket()->getId()), [
            '_csrf_token' => 'token-invalido',
        ]);
        $this->assertResponseRedirects();

        $em->clear();
        $reloaded = $em->getRepository(WeeklyBasket::class)->find($wbId);
        $this->assertNotNull($reloaded, 'Sin token CSRF válido la entrega no debe retirarse.');
        $this->assertSame(
            $statusBefore,
            $reloaded->getWeeklyBasketStatus()?->getId(),
            'Sin token CSRF válido, el estado de la entrega no debe cambiar.',
        );
    }

    /**
     * POST de mover sin token CSRF válido redirige y NO crea ningún
     * PartnerDeliveryShift. Guarda de seguridad del endpoint del socix.
     */
    public function testCalendarMoveRechazaCsrfInvalido(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb);
        $basketId = $wb->getBasket()->getId();

        $client->request('POST', sprintf('/panel/calendar/move/%d', $basketId), [
            '_csrf_token' => 'token-invalido',
            'to_basket_id' => $basketId,
        ]);
        $this->assertResponseRedirects();

        $em->clear();
        $shift = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner->getId(), 'fromBasket' => $basketId]);
        $this->assertNull($shift, 'Sin token CSRF válido no debe crearse ningún cambio puntual.');
    }

    /**
     * POST de "recoger en otro nodo" (traslado puntual de nodo) sin token CSRF
     * válido redirige y NO crea ningún PartnerNodeOverride. La guarda CSRF se
     * evalúa antes que el plazo y la resolución de datos, así que es determinista
     * (no depende de la fecha ni de que las fixtures cableen nodos a los grupos).
     */
    public function testChangeGroupRechazaCsrfInvalido(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb);
        $basketId = $wb->getBasket()->getId();

        $client->request('POST', '/panel/cesta/change-group', [
            '_csrf_token' => 'token-invalido',
            'basket_id' => $basketId,
            'group_id' => 1,
        ]);
        $this->assertResponseRedirects();

        $em->clear();
        $override = $em->getRepository(PartnerNodeOverride::class)
            ->findOneBy(['partner' => $partner->getId(), 'basket' => $basketId]);
        $this->assertNull($override, 'Sin token CSRF válido no debe crearse ningún traslado de nodo.');
    }

    /**
     * Mover la cesta con el plazo de autoservicio cerrado se rechaza aunque falten días
     * para la recogida: pasado el cierre el listado del reparto ya puede haberse enviado,
     * y el cambio no llegaría a la hoja impresa. Antes solo se exigía que la recogida no
     * fuera hoy o ya pasada.
     */
    public function testCalendarMoveRespetaElPlazoDeAutoservicio(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb);
        $basket = $wb->getBasket();
        $basketId = $basket->getId();
        $token = $this->moveCsrfToken($client, $basket);
        $this->closeEveryDeadline($client);

        $client->request('POST', sprintf('/panel/calendar/move/%d', $basketId), [
            '_csrf_token' => $token,
            'to_basket_id' => $basketId,
        ]);
        $this->assertResponseRedirects();
        $errors = $this->flashes($client, 'error');

        $em = $this->em($client);
        $em->clear();
        $shift = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner->getId(), 'fromBasket' => $basketId]);

        $this->assertNull($shift, 'Con el plazo cerrado no debe crearse ningún cambio puntual.');
        $this->assertStringContainsString('el plazo terminó', implode(' ', $errors));
    }

    /**
     * Mover sólo los HUEVOS va por el mismo endpoint y respeta el mismo plazo: los huevos
     * también salen en el listado del reparto.
     */
    public function testCalendarMoveHuevosRespetaElPlazoDeAutoservicio(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb);
        $basket = $wb->getBasket();
        $token = $this->moveCsrfToken($client, $basket);
        $this->closeEveryDeadline($client);

        $client->request('POST', sprintf('/panel/calendar/move/%d', $basket->getId()), [
            '_csrf_token' => $token,
            'to_basket_id' => $basket->getId(),
            'component_id' => BasketComponent::ID_EGGS,
        ]);
        $this->assertResponseRedirects();

        $this->assertStringContainsString('el plazo terminó', implode(' ', $this->flashes($client, 'error')));
    }

    /**
     * Recuperar una cesta aparcada en un día con el plazo cerrado se rechaza: ese día
     * ganaría una cesta que ya no saldría en su listado. La cesta sigue aparcada.
     */
    public function testCalendarRecoverRespetaElPlazoDeAutoservicio(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb);
        $basket = $wb->getBasket();
        $basketId = $basket->getId();
        $token = $this->moveCsrfToken($client, $basket);
        // Tras la petición el contenedor es otro: el EM y las entidades, del nuevo.
        $em = $this->em($client);
        $client->getContainer()->get(DeliveryShiftApplier::class)->applySkipIntent(
            $this->reloadSocixPartner($em),
            $em->getRepository(Basket::class)->find($basketId),
            null,
            'test:setup',
        );
        $this->closeEveryDeadline($client);

        $client->request('POST', sprintf('/panel/calendar/recover/%d', $basketId), [
            '_csrf_token' => $token,
            'to_basket_id' => $basketId,
        ]);
        $this->assertResponseRedirects();
        $errors = $this->flashes($client, 'error');

        $em = $this->em($client);
        $em->clear();
        $partner = $this->reloadSocixPartner($em);
        $basket = $em->getRepository(Basket::class)->find($basketId);
        $intent = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner->getId(), 'fromBasket' => $basketId]);
        $stillParked = $intent !== null && $intent->isParked();

        // db_test no tiene rollback transaccional: restauramos ANTES de las aserciones.
        if ($intent !== null) {
            $client->getContainer()->get(DeliveryShiftApplier::class)->cancelSkipIntent($intent, 'test:cleanup');
        }
        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share !== null) {
            $client->getContainer()->get(WeeklyBasketGenerator::class)->materializeShareDelivery($basket, $share);
            $em->flush();
        }

        $this->assertTrue($stillParked, 'Con el plazo cerrado la cesta debe seguir aparcada.');
        $this->assertStringContainsString('cerró el plazo', implode(' ', $errors));
    }

    /**
     * Deshacer un traslado sumando con el plazo cerrado se rechaza: cambiaría dos
     * listados (el día que recibió la cesta y su semana de origen). El traslado sigue.
     *
     * Las fixtures dan al socix un único día de recogida, así que el traslado no se hace
     * con AccumulatingMove (necesita un origen con cesta): se deja directamente el intent
     * que éste crea, «no recoge en el origen, sumada al destino», que es lo único que el
     * endpoint mira antes del plazo.
     */
    public function testCalendarUndoAccumulateRespetaElPlazoDeAutoservicio(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $this->farthestSocixWeeklyBasket($em, $partner);
        $this->assertNotNull($wb);
        $toId = $wb->getBasket()->getId();
        $from = $em->getRepository(Basket::class)->createQueryBuilder('b')
            ->where('b.id <> :to')
            ->setParameter('to', $toId)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        $this->assertNotNull($from, 'Las fixtures deberían tener más de una semana de reparto.');
        $fromId = $from->getId();
        $token = $this->moveCsrfToken($client, $wb->getBasket());

        // Tras la petición el contenedor es otro: el EM y las entidades, del nuevo.
        $em = $this->em($client);
        $client->getContainer()->get(DeliveryShiftApplier::class)->applySkipIntent(
            $this->reloadSocixPartner($em),
            $em->getRepository(Basket::class)->find($fromId),
            null,
            'test:setup',
            $em->getRepository(Basket::class)->find($toId),
        );
        $this->closeEveryDeadline($client);

        $client->request('POST', sprintf('/panel/calendar/accumulate/%d/undo', $toId), [
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();
        $errors = $this->flashes($client, 'error');

        $em = $this->em($client);
        $em->clear();
        $partner = $this->reloadSocixPartner($em);
        $shifts = $client->getContainer()->get(PartnerDeliveryShiftRepository::class)
            ->findAccumulatedInto($partner, $em->getRepository(Basket::class)->find($toId));

        // db_test no tiene rollback transaccional: restauramos ANTES de las aserciones.
        foreach ($shifts as $shift) {
            $client->getContainer()->get(DeliveryShiftApplier::class)->cancelSkipIntent($shift, 'test:cleanup');
        }

        $this->assertCount(1, $shifts, 'Con el plazo cerrado el traslado sumando debe seguir en pie.');
        $this->assertStringContainsString('el plazo terminó', implode(' ', $errors));
    }
}
