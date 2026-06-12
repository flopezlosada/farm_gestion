<?php

namespace App\Tests\Controller;

use App\Entity\Booking;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Agenda pública de eventos (Booking): /asambleas lista los eventos vigentes,
 * /evento/{id} muestra el detalle en anónimo y el sidebar del blog pinta la
 * caja de próximos eventos encima de las categorías (puntos 9 de la reunión
 * de admin del 2026-06-11, en versión provisional sin calendario visual).
 */
class PublicAgendaTest extends AbstractAuthenticatedTest
{
    /**
     * Limpia los eventos entre tests: la BBDD de test persiste entre casos y
     * los bookings sembrados por un test contaminarían los asserts del
     * siguiente (p. ej. el de la caja oculta sin eventos vigentes).
     */
    private function purgeBookings(EntityManagerInterface $em): void
    {
        // DQL DELETE no dispara lifecycle callbacks (PostRemove); aquí es
        // intencionado porque los bookings de test no tienen imagen en disco.
        $em->createQuery('DELETE FROM App\Entity\Booking b')->execute();
    }

    /**
     * Crea y persiste un evento público con fecha relativa a hoy.
     *
     * @param EntityManagerInterface $em
     * @param string $title Título del evento.
     * @param string $beginModifier Expresión relativa para beginAt (p. ej. '+10 days').
     * @return Booking
     */
    private function createBooking(EntityManagerInterface $em, string $title, string $beginModifier): Booking
    {
        $booking = new Booking();
        $booking->setTitle($title);
        $booking->setContent('<p>Contenido de prueba del evento.</p>');
        $booking->setBeginAt(new \DateTime($beginModifier));

        $em->persist($booking);
        $em->flush();

        return $booking;
    }

    /**
     * Arranca un cliente anónimo con la tabla de eventos limpia.
     *
     * @return array{0: KernelBrowser, 1: EntityManagerInterface}
     */
    private function anonymousClientWithCleanAgenda(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeBookings($em);

        return [$client, $em];
    }

    /**
     * /asambleas en anónimo lista el evento futuro y excluye el ya pasado.
     */
    public function testAsambleasListsUpcomingAndHidesPastEvents(): void
    {
        [$client, $em] = $this->anonymousClientWithCleanAgenda();
        $this->createBooking($em, 'Asamblea futura de prueba', '+10 days 18:30');
        $this->createBooking($em, 'Evento de hoy de prueba', 'today 12:00');
        $this->createBooking($em, 'Evento pasado de prueba', '-10 days');

        $client->request('GET', '/asambleas');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Asamblea futura de prueba', $content);
        // Un evento de hoy sigue vigente durante todo su día, aunque su hora
        // ya haya pasado: findUpcoming compara contra la medianoche de hoy.
        $this->assertStringContainsString('Evento de hoy de prueba', $content);
        $this->assertStringNotContainsString('Evento pasado de prueba', $content);
    }

    /**
     * El detalle /evento/{id} es público: 200 en anónimo con título y contenido.
     */
    public function testEventoDetailIsPublic(): void
    {
        [$client, $em] = $this->anonymousClientWithCleanAgenda();
        $booking = $this->createBooking($em, 'Taller de compost', '+5 days 11:00');

        $client->request('GET', '/evento/' . $booking->getId());

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Taller de compost', $content);
        $this->assertStringContainsString('Contenido de prueba del evento.', $content);
    }

    /**
     * El sidebar del blog pinta la caja "Próximos eventos" encima de las
     * categorías cuando hay eventos vigentes.
     */
    public function testBlogSidebarShowsUpcomingEventsBox(): void
    {
        [$client, $em] = $this->anonymousClientWithCleanAgenda();
        $this->createBooking($em, 'Fiesta de la cosecha', '+15 days');

        $client->request('GET', '/blog');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Próximos eventos', $content);
        $this->assertStringContainsString('Fiesta de la cosecha', $content);
        $this->assertStringContainsString(
            'Ver toda la agenda',
            $content,
            'La caja debería enlazar a la agenda completa de /asambleas.'
        );
    }

    /**
     * Sin eventos vigentes la caja no aparece (no se pinta el título vacío).
     */
    public function testBlogSidebarHidesBoxWithoutUpcomingEvents(): void
    {
        [$client, $em] = $this->anonymousClientWithCleanAgenda();
        $this->createBooking($em, 'Evento pasado de prueba', '-3 days');

        $client->request('GET', '/blog');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('Próximos eventos', $client->getResponse()->getContent());
    }

    /**
     * El CRUD de gestión exige login: en anónimo redirige al login.
     */
    public function testAdminCrudRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/gestion/eventos/');

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }

    /**
     * El admin ve el listado y el form de alta, y la edición persiste las
     * fechas nativas (DateTimeType single_text) sin los apaños de string del
     * controller viejo.
     */
    public function testAdminCanEditEventDatesWithNativeInputs(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeBookings($em);
        $booking = $this->createBooking($em, 'Asamblea editable', '+7 days 17:00');

        $client->request('GET', '/gestion/eventos/');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/gestion/eventos/new');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $crawler = $client->request('GET', '/gestion/eventos/' . $booking->getId() . '/edit');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $newBegin = (new \DateTimeImmutable('+20 days'))->setTime(19, 30);
        $form = $crawler->selectButton('Guardar cambios')->form([
            'booking[beginAt]' => $newBegin->format('Y-m-d\TH:i'),
            'booking[endAt]' => '',
            'booking[title]' => 'Asamblea editada',
            'booking[content]' => '<p>Contenido editado.</p>',
        ]);
        $client->submit($form);

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $em->clear();
        $reloaded = $em->getRepository(Booking::class)->find($booking->getId());
        $this->assertSame('Asamblea editada', $reloaded->getTitle());
        $this->assertSame(
            $newBegin->format('Y-m-d H:i'),
            $reloaded->getBeginAt()->format('Y-m-d H:i'),
            'La fecha de inicio debería persistirse tal cual desde el input datetime-local.'
        );
        $this->assertNull($reloaded->getEndAt());
    }
}
