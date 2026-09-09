<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\User;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Las tres puertas del voluntariado del socix.
 *
 * Existe porque partir una pantalla en varias es exactamente el cambio que se
 * rompe en silencio: una ruta que deja de existir, una pestaña que apunta a
 * donde no es, o —lo más caro— un bloque que se muda de pantalla y se lleva
 * consigo un aviso que ya nadie ve.
 */
class PanelVolunteeringTabsTest extends AbstractPartnerAuthenticatedTest
{
    /**
     * Las tres responden y todas llevan la barra: si una se queda sin
     * pestañas, es una pantalla sin salida.
     */
    public function testLasTresPestanasRespondenYLlevanLaBarra(): void
    {
        $client = $this->socixWithModuleOn();

        $rutas = [
            '/panel/voluntariado',
            '/panel/voluntariado/calendario',
            '/panel/voluntariado/mis-turnos',
        ];

        foreach ($rutas as $ruta) {
            $crawler = $client->request('GET', $ruta);

            $this->assertResponseIsSuccessful(sprintf('%s tiene que responder.', $ruta));
            $this->assertSame(
                3,
                $crawler->filter('.vol-tabs .csa-tabs__item')->count(),
                sprintf('%s tiene que llevar las tres pestañas.', $ruta)
            );
        }
    }

    /**
     * Cada pantalla marca SU pestaña como activa. Sin esto, la barra sale igual
     * en las tres y deja de decir dónde estás, que es la mitad de su trabajo.
     */
    public function testCadaPantallaMarcaSuPestana(): void
    {
        $client = $this->socixWithModuleOn();

        foreach ([
            '/panel/voluntariado' => 'Hace falta',
            '/panel/voluntariado/calendario' => 'Cuadrante',
            '/panel/voluntariado/mis-turnos' => 'Mis turnos',
        ] as $ruta => $rotulo) {
            $crawler = $client->request('GET', $ruta);

            $activa = $crawler->filter('.vol-tabs .csa-tabs__item.active');
            $this->assertCount(1, $activa, sprintf('%s tiene que marcar UNA pestaña.', $ruta));
            $this->assertStringContainsString($rotulo, $activa->text());
        }
    }

    /**
     * Lo que falta por confirmar se avisa desde CUALQUIER pestaña.
     *
     * Es la razón de que el contador viva en la barra y no dentro de «mis turnos»:
     * es una pregunta con respuesta de un clic, y hasta que no se conteste esas
     * horas no las tiene nadie. Enterrada en su propia pestaña, quien no entra
     * ahí no se entera nunca — y al mudarla de sitio ése era el riesgo real.
     */
    public function testLoQuePideConfirmacionSeAvisaDesdeLaPortada(): void
    {
        $client = $this->socixWithModuleOn();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $partner = $em->getRepository(User::class)
            ->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME)
            ->getPartner();

        // Un turno que ya pasó y al que fue, sin decir todavía si pudo ir.
        $offer = (new VolunteerOffer())
            ->setTitle('Tabs pendiente de confirmar '.uniqid())
            ->setSlots(3)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);
        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('-3 days'));
        $offer->addShift($shift);
        $signup = (new VolunteerSignup())->setShift($shift)->setPartner($partner);

        $em->persist($offer);
        $em->persist($shift);
        $em->persist($signup);
        $em->flush();

        $crawler = $client->request('GET', '/panel/voluntariado');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('.vol-tabs .csa-tabs__count')->count(),
            'La portada tiene que avisar de que hay algo por confirmar en «mis turnos».'
        );
    }

    /**
     * Decir «no pude» no hace desaparecer el turno.
     *
     * Es el fallo que se vio en pantalla: al contestar que no, la inscripción
     * salía de los pendientes —correcto— y no entraba en ninguna otra lista, así
     * que la tarjeta se esfumaba sin dejar rastro. Quien pulsaba no podía saber
     * si su respuesta se había guardado, y quien se equivocaba de botón no tenía
     * nada que corregir.
     *
     * Sigue sin sumar horas, que es lo que no se puede romper al arreglarlo.
     */
    public function testDecirQueNoPudisteNoBorraElTurnoDeLaPantalla(): void
    {
        $client = $this->socixWithModuleOn();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $partner = $em->getRepository(User::class)
            ->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME)
            ->getPartner();

        $titulo = 'Tabs no pude '.uniqid();
        $offer = (new VolunteerOffer())
            ->setTitle($titulo)
            ->setSlots(3)
            ->setCreditedMinutes(120)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);
        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('-2 days'));
        $offer->addShift($shift);
        $signup = (new VolunteerSignup())->setShift($shift)->setPartner($partner);

        $em->persist($offer);
        $em->persist($shift);
        $em->persist($signup);
        $em->flush();

        // Contesta que no pudo ir, por el mismo camino que la pantalla.
        $client->request('POST', sprintf('/panel/voluntariado/%d/confirmar', $shift->getId()), [
            'attended' => '0',
            '_csrf_token' => static::getContainer()->get('security.csrf.token_manager')
                ->getToken('panel_volunteering')->getValue(),
        ]);
        $client->followRedirect();

        $this->assertSelectorTextContains('.vol-done', $titulo, 'Lo contestado tiene que seguir viéndose.');

        $em->refresh($signup);
        $this->assertFalse($signup->getAttended());
        $this->assertNull($signup->getCreditedMinutes(), 'Un «no pude» no computa horas.');
    }

    private function socixWithModuleOn(): KernelBrowser
    {
        $client = $this->createPartnerAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        return $client;
    }
}
