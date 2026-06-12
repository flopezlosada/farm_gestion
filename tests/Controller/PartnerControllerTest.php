<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\BasketShare;
use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyBasketSkipper;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Smoke test del listado y la edición de socixs.
 */
class PartnerControllerTest extends AbstractAuthenticatedTest
{
    /**
     * Id del User creado por testGrantAccessProvisionsUserFromShow, para
     * limpiarlo en tearDown aunque una assertion intermedia falle (si quedara
     * huérfano en db_test, contaminaría pases posteriores sin recarga de
     * fixtures).
     */
    private ?int $grantedUserId = null;

    /**
     * Limpieza de estado creado por los tests de acceso (ver $grantedUserId).
     */
    protected function tearDown(): void
    {
        if ($this->grantedUserId !== null) {
            $em = static::getContainer()->get('doctrine')->getManager();
            $user = $em->find(\App\Entity\User::class, $this->grantedUserId);
            if ($user !== null) {
                $em->remove($user);
                $em->flush();
            }
            $this->grantedUserId = null;
        }

        parent::tearDown();
    }

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
     * GET de la ficha de un socio devuelve 200. Cubre en runtime la ficha tras
     * extraer los builders de "recoger en otro nodo" (semanas + nodos destino) al
     * servicio compartido PickupRelocationOptions: la ficha los consume vía el
     * servicio, así que un 500 en ese camino lo delata aquí.
     */
    public function testPartnerShowReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner, 'Fixtures sin socix de prueba (PartnerUserFixtures).');

        $client->request('GET', sprintf('/gestion/partner/%d', $partner->getId()));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
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
     * CSRF real que pinta la pantalla del mes de la entrega y, al postearlo, el
     * "no recoge" LIBERA el día — retira el WeeklyBasket y deja un intent durable
     * sin destino (to=null), no un WB clavado en estado 2. Así el día queda libre y
     * la entrega aparcada es recuperable. (Reparto/PDF leen status 1: un WB retirado
     * queda fuera igual que uno saltado — verificado por caracterización.)
     */
    public function testDeliveryCalendarSkipFreesTheDayAndLeavesIntent(): void
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

        $partnerId = $partner->getId();
        $wbId = $wb->getId();
        $basketId = $wb->getBasket()->getId();
        $date = $wb->getBasket()->getDate();

        // La pantalla del mes, con esa entrega seleccionada (?sel), pinta el panel
        // con el formulario de saltar y su token.
        $crawler = $client->request('GET', sprintf(
            '/gestion/partner/%d/calendar?year=%d&month=%d&sel=%d',
            $partnerId,
            (int) $date->format('Y'),
            (int) $date->format('n'),
            $basketId,
        ));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $tokenInput = $crawler->filter(sprintf('#rcal-skip-form-%d input[name="_csrf_token"]', $basketId));
        $this->assertGreaterThan(0, $tokenInput->count(), 'El calendario debería pintar el formulario de saltar con su token.');

        $client->request('POST', sprintf('/gestion/partner/%d/calendar/skip/%d', $partnerId, $basketId), [
            '_csrf_token' => $tokenInput->attr('value'),
        ]);
        $this->assertResponseRedirects();

        // Tras las requests, el EM a usar es el del contenedor del último kernel: así es
        // el MISMO que inyectan los servicios del cleanup (cancelSkipIntent/generator).
        // Con el $em capturado antes de las requests, las entidades llegarían DETACHED al
        // applier y remove() lanzaría.
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $basket = $em->getRepository(Basket::class)->find($basketId);
        $partner = $em->getRepository(Partner::class)->find($partnerId);
        $wbAfter = $em->getRepository(WeeklyBasket::class)->find($wbId);
        $intent = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partnerId, 'fromBasket' => $basketId]);

        $dayFreed = $wbAfter === null;
        $intentIsSkip = $intent !== null && $intent->isSkip();

        // db_test no tiene rollback transaccional: se restaura ANTES de las
        // aserciones (que podrían lanzar) recorriendo el camino "volver a recoger"
        // del controller — cancelar el intent y re-materializar la entrega.
        if ($intent !== null) {
            static::getContainer()->get(DeliveryShiftApplier::class)->cancelSkipIntent($intent, 'test:cleanup');
        }
        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share !== null) {
            static::getContainer()->get(WeeklyBasketGenerator::class)->materializeShareDelivery($basket, $share);
        }

        $this->assertTrue($dayFreed, 'Saltar debe LIBERAR el día: el WeeklyBasket se retira, no se deja en estado 2.');
        $this->assertTrue($intentIsSkip, 'Saltar debe dejar un intent "no recoge" durable (sin destino).');
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

    /**
     * Donación de cesta: asignar un pagador externo (set_payer) deja al donante
     * como payer_partner de la PBS activa del receptor, y quitarlo (clear_payer)
     * la revierte a "la paga ella misma". El receptor (PBS.partner) no cambia.
     */
    public function testSetAndClearPayerOnActiveBasket(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $share = $em->getRepository(PartnerBasketShare::class)->findOneBy(['is_active' => 1]);
        $this->assertNotNull($share, 'Fixtures sin ninguna cesta activa.');
        $receptor = $share->getPartner();

        $payer = null;
        foreach ($em->getRepository(Partner::class)->findBy(['status' => Partner::STATUS_ACTIVO]) as $candidate) {
            if ($candidate->getId() !== $receptor->getId()) {
                $payer = $candidate;
                break;
            }
        }
        $this->assertNotNull($payer, 'Fixtures sin un segundo socix activo para donar.');

        $shareId = $share->getId();
        $originalPayerId = $share->getPayerPartner()?->getId();

        // Asignar pagador (donación).
        $client->request('GET', sprintf('/gestion/partner/%d/%d/set_payer', $receptor->getId(), $payer->getId()));
        $this->assertResponseRedirects();

        $em->clear();
        $reloaded = $em->getRepository(PartnerBasketShare::class)->find($shareId);
        $this->assertSame($payer->getId(), $reloaded->getPayerPartner()?->getId(), 'set_payer debe fijar el pagador externo.');
        $this->assertSame($receptor->getId(), $reloaded->getPartner()->getId(), 'El receptor de la cesta no debe cambiar.');

        // Quitar pagador (vuelve a pagarla ella misma).
        $client->request('GET', sprintf('/gestion/partner/%d/clear_payer', $receptor->getId()));
        $this->assertResponseRedirects();

        $em->clear();
        $reloaded = $em->getRepository(PartnerBasketShare::class)->find($shareId);
        $this->assertNull($reloaded->getPayerPartner(), 'clear_payer debe revertir a la propia socix.');

        // db_test no hace rollback: restaura el pagador original si lo había.
        if ($originalPayerId !== null) {
            $reloaded->setPayerPartner($em->getRepository(Partner::class)->find($originalPayerId));
            $em->flush();
        }
    }

    /**
     * El picker de pagador (family?type=set_payer) renderiza 200 y ofrece
     * candidatos para donar la cesta de un socix.
     */
    public function testSetPayerPickerReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = $em->getRepository(Partner::class)->findOneBy(['status' => Partner::STATUS_ACTIVO]);
        $this->assertNotNull($partner, 'Fixtures sin ningún socix activo.');

        $client->request('GET', sprintf('/gestion/partner/%d/set_payer/family', $partner->getId()));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET del formulario de añadir cesta devuelve 200. Cubre en runtime el render
     * del form (campos condicionales de huevos, dropdowns csa, toggle gratuita) y
     * el cálculo del turno con fechas reales vía CohortChoiceBuilder: un 500 en ese
     * camino (campo huérfano, opción inexistente, etc.) lo delata aquí.
     */
    public function testAddBasketFormReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner, 'Fixtures sin socix de prueba (PartnerUserFixtures).');

        $client->request('GET', sprintf('/gestion/partner/%d/add_basket', $partner->getId()));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET del formulario de cambiar la cesta (cambio de modalidad con histórico)
     * devuelve 200. Regresión: al exponer egg_day_month_order en el form compartido,
     * change_modality colocaba mal el campo y CohortChoiceBuilder alimenta su turno;
     * este smoke caza un 500 de render en ese camino.
     */
    public function testChangeModalityFormReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $share = $em->getRepository(PartnerBasketShare::class)->findOneBy(['is_active' => 1]);
        $this->assertNotNull($share, 'Fixtures sin ninguna cesta activa.');

        $client->request('GET', sprintf('/gestion/partner/basket/share/%d/change-modality', $share->getId()));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * El alta de socio es sólo datos de persona (reunión admin 2026-06-11,
     * punto 3): el form NO ofrece grupo de recogida, y el POST crea la ficha
     * sin grupo y sin ninguna cesta. Grupo y cesta se asignan después desde
     * "añadir cesta", el único camino de creación de cesta (pasa por reconcile).
     */
    public function testNewPartnerCreatesPersonWithoutGroupNorBasket(): void
    {
        $client = $this->createAuthenticatedClient();

        $crawler = $client->request('GET', '/gestion/partner/new');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->selectButton('Crear socix')->form();
        $this->assertFalse(
            $form->has('partner[weekly_basket_group]'),
            'El alta no debe pedir grupo de recogida: se asigna junto con la cesta.'
        );

        $surname = 'Alta Solo Persona ' . uniqid();
        $form['partner[name]'] = 'TEST';
        $form['partner[surname]'] = $surname;
        $form['partner[email]'] = uniqid('alta') . '@example.test';
        $form['partner[inscription_date]'] = '2026-06-12';
        $form['partner[state]']->setValue(self::firstOptionValue($form['partner[state]']));
        $form['partner[city]']->setValue(self::firstOptionValue($form['partner[city]']));
        $form['partner[share_payment]']->setValue(self::firstOptionValue($form['partner[share_payment]']));
        $client->submit($form);

        $this->assertTrue($client->getResponse()->isRedirect(), 'El alta válida debe redirigir a la ficha.');

        $em = static::getContainer()->get('doctrine')->getManager();
        $partner = $em->getRepository(Partner::class)->findOneBy(['surname' => $surname]);
        $this->assertNotNull($partner, 'El alta no creó el socio.');
        $this->assertNull($partner->getWeeklyBasketGroup(), 'El alta no debe asignar grupo de recogida.');
        $this->assertCount(0, $partner->getPartnerBasketShares(), 'El alta no debe crear ninguna cesta.');

        $em->remove($partner);
        $em->flush();
    }

    /**
     * "Añadir cesta" pide el grupo de recogida cuando el socio no lo tiene
     * (el alta ya no lo fija) y, al guardar, lo persiste en la ficha además
     * de crear la cesta. La fecha de inicio lejana evita que reconcile
     * materialice semanas (no hay semanas generadas tan a futuro) y mantiene
     * el test autocontenido.
     */
    public function testAddBasketAsksAndPersistsPickupGroupWhenMissing(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname('Sin Grupo ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $em->persist($partner);
        $em->flush();
        $partnerId = $partner->getId();

        $crawler = $client->request('GET', sprintf('/gestion/partner/%d/add_basket', $partnerId));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->selectButton('Añadir cesta')->form();
        $this->assertTrue(
            $form->has('partner_basket_share[pickupGroup]'),
            'Sin grupo en la ficha, el form de cesta debe pedir el grupo de recogida.'
        );

        $groupValue = self::firstOptionValue($form['partner_basket_share[pickupGroup]']);
        $form['partner_basket_share[pickupGroup]']->setValue($groupValue);
        // Semanal (id 1): modalidad simple, sin pareja compartida ni huevos.
        $form['partner_basket_share[basket_share]']->setValue('1');
        $form['partner_basket_share[start_date]'] = '2030-01-04';
        $client->submit($form);

        $this->assertTrue(
            $client->getResponse()->isRedirect(sprintf('/gestion/partner/%d', $partnerId)),
            'Tras crear la cesta debe redirigir a la ficha.'
        );

        $em->clear();
        $fresh = $em->find(Partner::class, $partnerId);
        $this->assertNotNull($fresh->getWeeklyBasketGroup(), 'El grupo elegido debe quedar fijado en la ficha.');
        $this->assertSame((int) $groupValue, $fresh->getWeeklyBasketGroup()->getId());
        $shares = $em->getRepository(PartnerBasketShare::class)->findBy(['partner' => $fresh]);
        $this->assertCount(1, $shares, 'Debe haberse creado exactamente una cesta.');

        // Limpieza: lo que el flujo crea alrededor (semanas materializadas si
        // las hubiera, histórico de eventos) y la propia ficha.
        foreach ($em->getRepository(WeeklyBasket::class)->findBy(['partner' => $fresh]) as $weeklyBasket) {
            $em->remove($weeklyBasket);
        }
        foreach ($em->getRepository(PartnerEvent::class)->findBy(['partner' => $fresh]) as $event) {
            $em->remove($event);
        }
        foreach ($shares as $share) {
            $em->remove($share);
        }
        $em->remove($fresh);
        $em->flush();
    }

    /**
     * Elegir (vía ?group=N) un grupo cuyo nodo es QUINCENAL refiltra el form:
     * las modalidades de reparto semanal desaparecen del select y el grupo
     * queda preseleccionado. Los grupos de las fixtures no tienen nodo, así
     * que el escenario crea el suyo (nodo quincenal + grupo) para ejercitar
     * el camino real de Cascorro/Midori, no el fallback de nodo NULL.
     */
    public function testAddBasketWithBiweeklyGroupExcludesWeeklyShares(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $node = (new Node())
            ->setName('TEST Nodo Quincenal ' . uniqid())
            ->setDeliveryWeekday(3)
            ->setCadence(Node::CADENCE_BIWEEKLY)
            // Un nodo quincenal sin ancla lanza LogicException al calcular
            // sus fechas (NodeDeliveryDate::basketAlignsWithAnchor).
            ->setAnchorDate(new \DateTimeImmutable('2026-06-03'));
        $group = (new WeeklyBasketGroup())
            ->setName('TEST Grupo Quincenal ' . uniqid())
            ->setColor('#cccccc')
            ->setNode($node);
        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname('Sin Grupo Quincenal ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $em->persist($node);
        $em->persist($group);
        $em->persist($partner);
        $em->flush();
        [$partnerId, $groupId, $nodeId] = [$partner->getId(), $group->getId(), $node->getId()];

        $crawler = $client->request('GET', sprintf('/gestion/partner/%d/add_basket?group=%d', $partnerId, $groupId));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->selectButton('Añadir cesta')->form();
        $this->assertSame(
            (string) $groupId,
            $form['partner_basket_share[pickupGroup]']->getValue(),
            'El grupo pasado por ?group debe llegar preseleccionado.'
        );
        $offered = $form['partner_basket_share[basket_share]']->availableOptionValues();
        foreach (BasketShare::IDS_WEEKLY as $weeklyId) {
            $this->assertNotContains(
                (string) $weeklyId,
                $offered,
                'Un nodo quincenal no puede ofrecer modalidades de reparto semanal.'
            );
        }

        $em->remove($em->find(Partner::class, $partnerId));
        $em->remove($em->find(WeeklyBasketGroup::class, $groupId));
        $em->remove($em->find(Node::class, $nodeId));
        $em->flush();
    }

    /**
     * El botón "Dar acceso" de la ficha provisiona el User del socix aunque
     * el alta global esté cerrada (default del catálogo de settings). Es el
     * mecanismo de despliegue selectivo previo a abrir el grifo.
     */
    public function testGrantAccessProvisionsUserFromShow(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $userRepository = $em->getRepository(\App\Entity\User::class);

        // Un socix con email cuya cuenta NO exista aún (las fixtures solo
        // crean users para admin y para el socix de PartnerUserFixtures).
        $candidates = $em->getRepository(Partner::class)->createQueryBuilder('p')
            ->where("p.email IS NOT NULL AND p.email != ''")
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        $partner = null;
        foreach ($candidates as $candidate) {
            if ($userRepository->loadUserByIdentifier(mb_strtolower(trim($candidate->getEmail()))) === null) {
                $partner = $candidate;
                break;
            }
        }
        $this->assertNotNull($partner, 'Las fixtures no dejan ningún socix con email y sin cuenta.');

        $crawler = $client->request('GET', '/gestion/partner/' . $partner->getId());
        $this->assertResponseIsSuccessful();

        // Sin enviar el magic-link: aquí se prueba el provisioning, no el correo.
        $form = $crawler->selectButton('Dar acceso')->form();
        $form['send_link']->untick();
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/partner/' . $partner->getId());

        $email = mb_strtolower(trim($partner->getEmail()));
        $user = $userRepository->loadUserByIdentifier($email);
        $this->assertNotNull($user, 'Dar acceso no ha creado el User.');
        $this->grantedUserId = $user->getId();
        $this->assertFalse($user->isPasswordSet());

        // La ficha pasa a mostrar la cuenta en vez del botón.
        $crawler = $client->followRedirect();
        $this->assertStringContainsString($email, $crawler->filter('body')->text());
    }

    /**
     * Primer valor no vacío de un select del crawler (salta el placeholder).
     */
    private static function firstOptionValue(ChoiceFormField $field): string
    {
        $values = array_values(array_filter(
            $field->availableOptionValues(),
            static fn (string $value): bool => $value !== '',
        ));
        self::assertNotEmpty($values, 'El select no ofrece ninguna opción.');

        return $values[0];
    }
}
