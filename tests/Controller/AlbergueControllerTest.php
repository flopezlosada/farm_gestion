<?php

namespace App\Tests\Controller;

use App\Entity\Helper;
use App\Entity\HelperBasketSkip;
use App\Entity\HelperSource;
use App\Entity\HostingCapacity;
use App\Entity\InternshipDetail;
use App\Entity\Stay;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Smoke tests funcionales del módulo albergue. Los índices ya los barre
 * GestionSmokeRoutesTest; aquí se cubre lo que ese barrido no ve:
 *   - el round-trip del CRUD de voluntarios por formulario, y
 *   - el GUARD DE AFORO de extremo a extremo (HTTP → controller → servicio):
 *     una estancia confirmada que satura el aforo se rechaza sin persistir.
 *
 * Autocontenido: crea sus datos por EntityManager, conduce los formularios con
 * el crawler (CSRF incluido) y limpia al final, para no depender de fixtures de
 * albergue ni ensuciar db_test. Usa un mes lejano (2099-06) para no chocar con
 * ningún dato.
 *
 * Módulo albergue, Fase 1 (2026-06-17).
 */
class AlbergueControllerTest extends AbstractAuthenticatedTest
{
    /** Mes aislado para las pruebas de aforo (lejos de cualquier dato real). */
    private const TEST_YEAR = 2099;
    private const TEST_MONTH = 6;

    /**
     * Alta de un voluntario por el formulario: redirige a su ficha y la fila
     * queda persistida; la ficha responde 200.
     */
    public function testCreateHelperRoundTrip(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em();
        $source = $this->createSource($em, 'TEST WWOOF ' . uniqid());
        $sourceId = $source->getId();
        $name = 'TEST Helper ' . uniqid();

        $crawler = $client->request('GET', '/gestion/albergue/helpers/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['helper[name]'] = $name;
        $form['helper[source]'] = (string) $sourceId;
        $client->submit($form);

        $this->assertResponseRedirects();

        $helper = $this->em()->getRepository(Helper::class)->findOneBy(['name' => $name]);
        $this->assertNotNull($helper, 'El voluntario debería haberse creado.');

        $client->request('GET', sprintf('/gestion/albergue/helpers/%d', $helper->getId()));
        $this->assertResponseIsSuccessful();

        // Limpieza.
        $em = $this->em();
        $em->remove($em->getRepository(Helper::class)->find($helper->getId()));
        $em->remove($em->getRepository(HelperSource::class)->find($sourceId));
        $em->flush();
    }

    /**
     * El calendario de ocupación responde 200, tanto en la ventana por defecto
     * como navegando a un mes concreto. No lo cubre GestionSmokeRoutesTest (la
     * ruta no termina en "/").
     */
    public function testTimelineRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/albergue/timeline');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/gestion/albergue/timeline?from=2099-06');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/gestion/albergue/timeline?view=months');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Las métricas por procedencia responden 200 (año por defecto y concreto).
     */
    public function testStatsRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/albergue/stats');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/gestion/albergue/stats?year=2099');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Guard de aforo: con 1 plaza el mes, una primera estancia confirmada cabe;
     * una segunda confirmada que solapa se rechaza (no redirige, no persiste) y
     * la respuesta avisa del conflicto.
     */
    public function testStayGuardRejectsOverbooking(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em();

        $source = $this->createSource($em, 'TEST source ' . uniqid());
        $helperA = $this->createHelper($em, 'TEST A ' . uniqid(), $source);
        $helperB = $this->createHelper($em, 'TEST B ' . uniqid(), $source);
        $capacity = (new HostingCapacity())
            ->setYear(self::TEST_YEAR)
            ->setMonth(self::TEST_MONTH)
            ->setOpen(true)
            ->setCapacity(1);
        $em->persist($capacity);
        $em->flush();

        $sourceId = $source->getId();
        $helperAId = $helperA->getId();
        $helperBId = $helperB->getId();
        $capacityId = $capacity->getId();

        // Primera estancia confirmada: 1 cama sobre aforo 1 → cabe.
        $this->submitStay($client, $helperAId, '2099-06-10', '2099-06-15', Stay::STATUS_CONFIRMED);
        $this->assertResponseRedirects(sprintf('/gestion/albergue/helpers/%d', $helperAId));

        $confirmed = $this->countConfirmedStays();
        $this->assertSame(1, $confirmed, 'La primera estancia confirmada debería haberse guardado.');

        // Segunda confirmada que solapa: 2 camas sobre aforo 1 → rechazada.
        $crawler = $this->submitStay($client, $helperBId, '2099-06-12', '2099-06-18', Stay::STATUS_CONFIRMED);

        $this->assertResponseStatusCodeSame(200, 'El guard debería re-renderizar el form, no redirigir.');
        $this->assertSame(1, $this->countConfirmedStays(), 'La estancia que satura NO debería persistirse.');
        $this->assertStringContainsString('No cabe', $crawler->filter('body')->text(), 'Debería avisar de que no cabe.');

        // La MISMA segunda estancia como solicitada sí entra (no ocupa aforo).
        $this->submitStay($client, $helperBId, '2099-06-12', '2099-06-18', Stay::STATUS_REQUESTED);
        $this->assertResponseRedirects(sprintf('/gestion/albergue/helpers/%d', $helperBId));

        // Limpieza (estancias → helpers → capacity → source).
        $em = $this->em();
        foreach ($em->getRepository(Stay::class)->findBy(['helper' => [$helperAId, $helperBId]]) as $stay) {
            $em->remove($stay);
        }
        $em->flush();
        $em->remove($em->getRepository(Helper::class)->find($helperAId));
        $em->remove($em->getRepository(Helper::class)->find($helperBId));
        $em->remove($em->getRepository(HostingCapacity::class)->find($capacityId));
        $em->remove($em->getRepository(HelperSource::class)->find($sourceId));
        $em->flush();
    }

    /**
     * Datos de prácticas: se crean por formulario para una estancia y, al borrar
     * la estancia, se borran en cascada (no queda huérfano).
     */
    public function testInternshipDetailCreateAndCascadeOnStayDelete(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em();
        $source = $this->createSource($em, 'TEST prácticas ' . uniqid());
        $helper = $this->createHelper($em, 'TEST estudiante ' . uniqid(), $source);
        $stay = (new Stay())
            ->setHelper($helper)
            ->setArrivalDate(new \DateTimeImmutable('2099-09-01'))
            ->setDepartureDate(new \DateTimeImmutable('2099-12-01'))
            ->setStatus(Stay::STATUS_REQUESTED);
        $em->persist($stay);
        $em->flush();

        $sourceId = $source->getId();
        $helperId = $helper->getId();
        $stayId = $stay->getId();

        $crawler = $client->request('GET', sprintf('/gestion/albergue/stays/%d/internship', $stayId));
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['internship_detail[tutorName]'] = 'Dra. Tutora';
        $form['internship_detail[reportStatus]'] = InternshipDetail::REPORT_DELIVERED;
        $client->submit($form);
        $this->assertResponseRedirects(sprintf('/gestion/albergue/helpers/%d', $helperId));

        $detail = $this->em()->getRepository(InternshipDetail::class)->findOneBy(['stay' => $stayId]);
        $this->assertNotNull($detail, 'Los datos de prácticas deberían haberse creado.');
        $this->assertTrue($detail->isReportDelivered());
        $detailId = $detail->getId();

        // Borrar la estancia debe llevarse sus datos de prácticas (cascade).
        // clear() fuerza una carga FRESCA del Stay (como en producción, donde lo
        // trae el resolver con su internshipDetail cargado por ser OneToOne
        // inverso); sin esto, el Stay del identity map tendría internshipDetail
        // a null y el cascade no dispararía.
        $em = $this->em();
        $em->clear();
        $em->remove($em->getRepository(Stay::class)->find($stayId));
        $em->flush();
        $this->assertNull(
            $this->em()->getRepository(InternshipDetail::class)->find($detailId),
            'Los datos de prácticas deberían borrarse en cascada con la estancia.',
        );

        // Limpieza.
        $em = $this->em();
        $em->remove($em->getRepository(Helper::class)->find($helperId));
        $em->remove($em->getRepository(HelperSource::class)->find($sourceId));
        $em->flush();
    }

    /**
     * Piloto del modelo lectura/escritura por sección. Un usuario con SOLO
     * ROLE_GESTION_ALBERGUE (lectura) ve el listado (200) pero no se le pinta
     * el botón de alta, y el firewall le rechaza con 403 cualquier escritura
     * (regla access_control por método HTTP, sin tocar el controller).
     */
    public function testReadOnlyRoleCanReadButNotWrite(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_GESTION_ALBERGUE']);
        $userId = $user->getId();
        $client->loginUser($user);

        // Lectura: el listado responde y sin botón de alta.
        $client->request('GET', '/gestion/albergue/helpers/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(
            'a[href$="/gestion/albergue/helpers/new"]',
            'Un solo-lectura no debería ver el botón de alta de voluntario.',
        );

        // Escritura: el firewall corta el POST por método antes del controller.
        $client->request('POST', '/gestion/albergue/helpers/new');
        $this->assertResponseStatusCodeSame(403);

        $em = $this->em();
        $em->remove($em->getRepository(User::class)->find($userId));
        $em->flush();
    }

    /**
     * Contraparte del piloto: un usuario de ESCRITURA (ROLE_GESTION_ALBERGUE_EDIT,
     * que incluye la lectura por jerarquía) sí ve el botón de alta y el firewall
     * le deja pasar al formulario de nuevo voluntario (200, no 403).
     */
    public function testEditRoleSeesWriteAffordances(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_GESTION_ALBERGUE_EDIT']);
        $userId = $user->getId();
        $client->loginUser($user);

        $client->request('GET', '/gestion/albergue/helpers/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(
            'a[href$="/gestion/albergue/helpers/new"]',
            'Un usuario de escritura debería ver el botón de alta de voluntario.',
        );

        $client->request('GET', '/gestion/albergue/helpers/new');
        $this->assertResponseIsSuccessful();

        $em = $this->em();
        $em->remove($em->getRepository(User::class)->find($userId));
        $em->flush();
    }

    /**
     * Calendario de recogida del voluntario y toggle "no recoge": con cesta
     * activa y una estancia confirmada que cubre semanas de reparto de Torremocha,
     * el calendario lista esas semanas; saltar una crea el HelperBasketSkip y
     * volver a recogerla lo borra (round-trip por HTTP). Torremocha (viernes) y
     * los baskets 2026-06-19/26 existen en db_test.
     */
    public function testHelperBasketCalendarSkipRoundTrip(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em();

        $source = $this->createSource($em, 'TEST cesta ' . uniqid());
        $helper = $this->createHelper($em, 'TEST voluntario cesta ' . uniqid(), $source);
        $helper->setBasketActive(true)->setBasketVegBaskets(1)->setBasketEggDozens(1.0);
        $stay = (new Stay())
            ->setHelper($helper)
            ->setArrivalDate(new \DateTimeImmutable('2026-06-15'))
            ->setDepartureDate(new \DateTimeImmutable('2026-07-15'))
            ->setStatus(Stay::STATUS_CONFIRMED);
        $em->persist($stay);
        $em->flush();

        $sourceId = $source->getId();
        $helperId = $helper->getId();
        $stayId = $stay->getId();

        // clear() fuerza que la petición cargue un Helper FRESCO (como en
        // producción, donde MapEntity lo trae de cero): si no, el helper recién
        // creado conserva en el identity map su colección stays vacía (nunca se
        // hizo addStay, sólo stay->setHelper), y el calendario saldría sin semanas.
        $this->em()->clear();

        // El calendario lista la semana del 19/06/2026 con su botón de saltar.
        $crawler = $client->request('GET', sprintf('/gestion/albergue/helpers/%d/calendario', $helperId));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('19/06/2026', $crawler->filter('body')->text());

        // Saltar esa semana → redirige al calendario y persiste el skip.
        $form = $crawler->filterXPath('//form[.//input[@value="2026-06-19"]]')->form();
        $client->submit($form);
        $this->assertResponseRedirects(sprintf('/gestion/albergue/helpers/%d/calendario', $helperId));

        $skip = $this->em()->getRepository(HelperBasketSkip::class)
            ->findOneBy(['helper' => $helperId, 'date' => new \DateTimeImmutable('2026-06-19')]);
        $this->assertNotNull($skip, 'El "no recoge" debería haberse guardado.');

        // Volver a recogerla → borra el skip.
        $crawler = $client->request('GET', sprintf('/gestion/albergue/helpers/%d/calendario', $helperId));
        $form = $crawler->filterXPath('//form[.//input[@value="2026-06-19"]]')->form();
        $client->submit($form);
        $this->assertResponseRedirects(sprintf('/gestion/albergue/helpers/%d/calendario', $helperId));

        $this->assertNull(
            $this->em()->getRepository(HelperBasketSkip::class)
                ->findOneBy(['helper' => $helperId, 'date' => new \DateTimeImmutable('2026-06-19')]),
            'Volver a recoger debería borrar el skip.',
        );

        // Limpieza (por si quedara algún skip, antes del helper).
        $em = $this->em();
        foreach ($em->getRepository(HelperBasketSkip::class)->findBy(['helper' => $helperId]) as $s) {
            $em->remove($s);
        }
        $em->remove($em->getRepository(Stay::class)->find($stayId));
        $em->flush();
        $em->remove($em->getRepository(Helper::class)->find($helperId));
        $em->remove($em->getRepository(HelperSource::class)->find($sourceId));
        $em->flush();
    }

    /**
     * Envía el formulario de nueva estancia de un voluntario con las fechas y el
     * estado dados, y devuelve el crawler de la respuesta.
     */
    private function submitStay(
        KernelBrowser $client,
        int $helperId,
        string $arrival,
        string $departure,
        string $status,
    ): Crawler {
        $crawler = $client->request('GET', sprintf('/gestion/albergue/stays/new/%d', $helperId));
        $form = $crawler->filter('form')->form();
        $form['stay[arrivalDate]'] = $arrival;
        $form['stay[departureDate]'] = $departure;
        $form['stay[status]'] = $status;

        return $client->submit($form);
    }

    /**
     * Cuenta las estancias confirmadas del mes de prueba (aislado).
     */
    private function countConfirmedStays(): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Stay::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.arrivalDate >= :from')
            ->andWhere('s.arrivalDate < :to')
            ->setParameter('status', Stay::STATUS_CONFIRMED)
            ->setParameter('from', new \DateTimeImmutable('2099-06-01'))
            ->setParameter('to', new \DateTimeImmutable('2099-07-01'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Crea y persiste una procedencia.
     */
    private function createSource(EntityManagerInterface $em, string $name): HelperSource
    {
        $source = (new HelperSource())->setName($name);
        $em->persist($source);
        $em->flush();

        return $source;
    }

    /**
     * Crea y persiste un voluntario con la procedencia dada.
     */
    private function createHelper(EntityManagerInterface $em, string $name, HelperSource $source): Helper
    {
        $helper = (new Helper())->setName($name)->setSource($source);
        $em->persist($helper);
        $em->flush();

        return $helper;
    }

    /**
     * EntityManager fresco del contenedor (evita identity map obsoleto entre
     * peticiones HTTP del cliente).
     */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
