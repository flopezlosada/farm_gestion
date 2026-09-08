<?php

namespace App\Tests\Controller;

use App\Entity\Partner;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «Pedírselo» a una persona desde la ficha de un turno.
 *
 * LO QUE SE PRUEBA AQUÍ ES QUE EL ENDPOINT NO SE FÍE DE LA PANTALLA, que es la
 * clase de fallo que este camino tiene: la lista de sugerencias descuenta a
 * quien no se le puede pedir, pero esa lista la pinta un GET y el POST recibe un
 * id de socix por la URL. Basta con tener la ficha cargada desde antes de que
 * esa persona cambiara de opinión —o con repetir una petición— para que el id
 * llegue igual.
 *
 * Y lo que está al otro lado no se puede deshacer: el aviso lleva push, y el
 * permiso de notificaciones del navegador se pierde una vez y para siempre. Por
 * eso los casos que se prueban son los de NO avisar.
 *
 * La autorización sobre el turno tiene su propio test
 * ({@see \App\Tests\Security\VolunteerOfferVoterTest}) y no se repite aquí.
 */
class VolunteeringAskPersonTest extends WebTestCase
{
    /**
     * Los overrides de configuración se limpian para no dejar el voluntariado
     * encendido a los demás tests, que cuentan con los defaults del catálogo.
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

    /**
     * 🔴 EL CASO QUE JUSTIFICA ESTE FICHERO. Quien marcó «no quiero que se me
     * avise de voluntariado» no recibe nada, aunque el POST llegue con su id.
     *
     * El token se saca del botón de OTRA persona a propósito: así se reproduce
     * exactamente lo que puede pasar de verdad —la pantalla no ofrece el botón de
     * quien se dio de baja de los avisos, pero la petición se puede formar igual—
     * y no una manipulación rara.
     */
    public function testAQuienPidioQueNoSeLeAviseNoSeLePide(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Pedir OptOut');
        $shift = $this->makeShift($em, 'Descargar la furgoneta', $area);
        $ofrecible = $this->makePartner($em, 'SI QUIERE', $area);
        $silenciada = $this->makePartner($em, 'NO QUIERE', $area, optOut: true);
        $em->flush();

        $token = $this->askToken($client, $shift, $ofrecible);

        $client->request('POST', $this->askUrl($shift, $silenciada), ['_csrf_token' => $token]);

        $this->assertResponseRedirects();
        $this->assertSame(
            0,
            $this->askedEvents($em, $silenciada),
            'No se le pidió nada, así que no puede quedar rastro de que se le pidiera.'
        );
    }

    /**
     * Y la pantalla no le ofrece el botón, que es la otra mitad de lo mismo: si
     * lo ofreciera, quien coordina se quedaría creyendo que ya se lo pidió.
     */
    public function testLaFichaNoOfreceElBotonDeQuienNoQuiereAvisos(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Pedir Boton');
        $shift = $this->makeShift($em, 'Montar el reparto', $area);
        $silenciada = $this->makePartner($em, 'NO QUIERE BOTON', $area, optOut: true);
        $em->flush();

        $crawler = $client->request('GET', '/gestion/voluntariado/turno/' . $shift->getId());
        $this->assertResponseIsSuccessful();

        $this->assertCount(
            0,
            $crawler->filter('form[action="' . $this->askUrl($shift, $silenciada) . '"]')
        );
    }

    /**
     * El camino feliz deja constancia de A QUIÉN se le pidió y PARA QUÉ TURNO.
     *
     * El turno va en el payload porque el rastro no tiene columna para él, así
     * que se comprueba ahí: si se perdiera, quien coordina no podría saber por
     * cuál de los turnos de una tarea semanal se le pidió ayuda a alguien.
     */
    public function testPedirseloDejaRastroConElTurno(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Pedir Feliz');
        $shift = $this->makeShift($em, 'Repartir cestas', $area);
        $quien = $this->makePartner($em, 'DISPONIBLE', $area);
        $em->flush();

        $token = $this->askToken($client, $shift, $quien);
        $client->request('POST', $this->askUrl($shift, $quien), ['_csrf_token' => $token]);

        $this->assertResponseRedirects('/gestion/voluntariado/turno/' . $shift->getId());

        $event = $em->getRepository(VolunteerEvent::class)->findOneBy([
            'type' => VolunteerEvent::TYPE_ASKED,
            'partner' => $quien,
        ]);

        $this->assertNotNull($event, 'Pedírselo tiene que quedar registrado.');
        $this->assertSame($shift->getId(), $event->getPayload()['shift'] ?? null);
        $this->assertNotEmpty($event->getPayload()['ways'] ?? [], 'Se guarda por dónde salió.');
    }

    /**
     * Sin token no se manda nada. Va con el resto porque este endpoint escribe y
     * empuja avisos: es exactamente el que no puede aceptar una petición ajena.
     */
    public function testSinTokenValidoNoSePideNada(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Pedir Csrf');
        $shift = $this->makeShift($em, 'Cargar la furgoneta', $area);
        $quien = $this->makePartner($em, 'SIN TOKEN', $area);
        $em->flush();

        $client->request('POST', $this->askUrl($shift, $quien), ['_csrf_token' => 'esto-no-vale']);

        $this->assertResponseRedirects();
        $this->assertSame(0, $this->askedEvents($em, $quien));
    }

    /**
     * A quien ya se apuntó no se le pide que venga. La lista lo descuenta, pero
     * puede haberse apuntado después de cargarse la pantalla, y recibir un «te
     * piden ayuda» para algo a lo que ya vas sólo genera desconcierto.
     */
    public function testAQuienYaEstaApuntadoNoSeLePide(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Pedir Apuntada');
        $shift = $this->makeShift($em, 'Pesar las cajas', $area);
        $otra = $this->makePartner($em, 'OTRA', $area);
        $apuntada = $this->makePartner($em, 'YA VA', $area);
        $shift->addSignup((new VolunteerSignup())->setPartner($apuntada));
        $em->flush();

        $token = $this->askToken($client, $shift, $otra);
        $client->request('POST', $this->askUrl($shift, $apuntada), ['_csrf_token' => $token]);

        $this->assertResponseRedirects();
        $this->assertSame(0, $this->askedEvents($em, $apuntada));
    }

    private function adminClient(): KernelBrowser
    {
        $client = static::createClient();
        $user = static::getContainer()->get('doctrine')->getRepository(User::class)->loadUserByIdentifier('admin');

        if (null === $user) {
            throw new \RuntimeException('Fixtures sin User admin; carga UserFixtures en db_test.');
        }

        $client->loginUser($user);
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function settings(): AppSettings
    {
        return static::getContainer()->get(AppSettings::class);
    }

    /**
     * La URL del endpoint, en un sitio: si cambia la ruta, cambia aquí y no en
     * cinco assertions.
     */
    private function askUrl(VolunteerShift $shift, Partner $partner): string
    {
        return sprintf('/gestion/voluntariado/turno/%d/pedirselo/%d', $shift->getId(), $partner->getId());
    }

    /**
     * El token del botón de esa persona, sacado de la ficha del turno. Es el
     * mismo para todas las filas (`csrf_token('volunteering_ask')`), y por eso
     * sirve para probar que el endpoint acepta una petición formada con el token
     * de otra fila.
     */
    private function askToken(KernelBrowser $client, VolunteerShift $shift, Partner $partner): string
    {
        $crawler = $client->request('GET', '/gestion/voluntariado/turno/' . $shift->getId());
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action="' . $this->askUrl($shift, $partner) . '"] input[name="_csrf_token"]');
        $this->assertGreaterThan(0, $form->count(), 'La ficha debería ofrecer el botón de esta persona.');

        return $form->first()->attr('value');
    }

    /**
     * Cuántas veces consta que se le pidió algo a esta persona.
     */
    private function askedEvents(EntityManagerInterface $em, Partner $partner): int
    {
        return \count($em->getRepository(VolunteerEvent::class)->findBy([
            'type' => VolunteerEvent::TYPE_ASKED,
            'partner' => $partner,
        ]));
    }

    private function makeCategory(EntityManagerInterface $em, string $name): VolunteerCategory
    {
        // Con sufijo: el nombre es único en la tabla y estos tests corren sobre
        // una db_test que no se vacía entre ejecuciones.
        $category = (new VolunteerCategory())->setName($name . ' ' . uniqid());
        $em->persist($category);

        return $category;
    }

    /**
     * Un turno futuro con plazas, de una tarea publicada del área dada. Se
     * devuelve el TURNO porque es sobre lo que trabaja la pantalla.
     */
    private function makeShift(
        EntityManagerInterface $em,
        string $title,
        VolunteerCategory $category,
    ): VolunteerShift {
        // Las plazas se declaran en la TAREA, no en el turno: el turno sólo las
        // lee (`VolunteerShift::getSlots()` delega). Con plazas libres el turno
        // sigue pidiendo gente, que es la condición para que la ficha llegue a
        // enseñar «a quién pedírselo».
        $offer = (new VolunteerOffer())
            ->setTitle($title . ' ' . uniqid())
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setSlots(4)
            ->addCategory($category);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('+3 days'));
        $offer->addShift($shift);
        $em->persist($offer);

        return $shift;
    }

    /**
     * Un socix con el área marcada, que es lo que le hace aparecer en «a quién
     * pedírselo».
     *
     * CON CORREO SIEMPRE: sin cuenta en la web ni correo en la ficha no hay por
     * dónde avisar, y entonces el botón no se pintaría y los casos pasarían por
     * el motivo equivocado.
     *
     * @param bool $optOut si ha pedido que no se le avise de voluntariado
     */
    private function makePartner(
        EntityManagerInterface $em,
        string $name,
        VolunteerCategory $category,
        bool $optOut = false,
    ): Partner {
        $partner = (new Partner())
            ->setName($name)
            ->setSurname('PEDIR ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setEmail(strtolower(uniqid('pedir')) . '@example.org');
        $partner->setVolunteeringOptOut($optOut);
        $partner->addVolunteerCategory($category);
        $em->persist($partner);

        return $partner;
    }
}
