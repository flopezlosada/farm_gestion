<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;

/**
 * La bandeja de avisos y la campanita.
 *
 * Lo que se protege aquí, por orden de gravedad si se rompiera:
 *  1. que un aviso sólo lo pueda abrir su destinatario (dentro va la fecha y el
 *     punto de recogida de una persona concreta);
 *  2. que abrir un aviso lo marque leído y lleve a la pantalla que lo contesta,
 *     que es lo único que baja el contador;
 *  3. que entrar en la bandeja NO marque nada leído, porque si lo hiciera
 *     bastaría pasar por delante para perder de vista un aviso sin haberlo leído.
 *
 * El EntityManager se pide FRESCO en cada paso ({@see em()}) y no se guarda en una
 * propiedad: cada petición del cliente reinicia el kernel y deja cerrado el
 * manager anterior, que es el patrón del resto de los tests funcionales.
 */
class NotificationInboxTest extends AbstractPartnerAuthenticatedTest
{
    /**
     * CON LA SESIÓN RECORDADA (cookie remember_me) TAMBIÉN SE ABRE, y este caso
     * existe porque el resto de la batería no lo veía: `loginUser()` crea siempre
     * una sesión PLENA, así que con `IS_AUTHENTICATED_FULLY` en el controlador los
     * tests pasaban en verde mientras en el navegador pulsar la campanita rebotaba
     * al panel — `/avisos` denegaba, el firewall mandaba a `/login`, éste veía
     * `getUser()` y redirigía a `/post-login`, que reparte a `dashboard` o `panel`.
     *
     * El token se mete en la SESIÓN y no en `security.token_storage`: es de donde
     * lo lee el firewall. Con `setToken()` a secas la petición sale 302 a /login y
     * el test pasaría por el motivo equivocado.
     */
    public function testLaBandejaSeAbreConLaSesionRecordada(): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get('doctrine')->getRepository(User::class)
            ->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME);
        self::assertNotNull($user, 'Fixtures sin User socix; carga PartnerUserFixtures en db_test.');

        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('_security_main', serialize(new RememberMeToken($user, 'main')));
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        $client->request('GET', '/avisos');

        self::assertResponseIsSuccessful('Con la cookie de sesión recordada la bandeja tiene que abrirse, no rebotar.');
        self::assertSelectorTextContains('.csa-page-header h1', 'Mis avisos');
    }

    public function testLaBandejaCargaParaUnSocix(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $client->request('GET', '/avisos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.csa-page-header h1', 'Mis avisos');
    }

    /**
     * EL SHELL SE ELIGE POR LA FICHA DE SOCIX, NO POR EL ROL, y este par de casos
     * existe porque hacerlo por el rol estaba MAL: la jerarquía de security.yaml
     * cuelga ROLE_PARTNER de ROLE_ADMIN, así que `is_granted('ROLE_PARTNER')` es
     * verdadero para cualquier admin y la bandeja le salía con el shell del panel
     * del socix —«Mi panel», «Mi calendario», «Voluntariado»— teniendo partner_id a
     * NULL: un panel personal sin nada personal que enseñar.
     *
     * Se comprueba por una marca del lateral del socix y no por el nombre del
     * layout: es lo que de verdad ve quien entra.
     */
    public function testUnaCuentaDeGestionSinFichaVeElShellDeGestion(): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get('doctrine')->getRepository(User::class)
            ->loadUserByIdentifier('admin');
        self::assertNotNull($user, 'Fixtures sin User admin; carga UserFixtures en db_test.');
        self::assertNull($user->getPartner(), 'El admin de las fixtures no debe tener ficha de socix.');

        $client->loginUser($user);
        $client->request('GET', '/avisos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Mi calendario');
        self::assertSelectorTextContains('.csa-page-header', 'Mi cuenta');
        // Y sin el enlace a las preferencias, que vive en /panel y sin ficha rebota.
        self::assertSelectorTextNotContains('body', 'Cómo te avisamos');
    }

    /**
     * La contrapartida: a quien SÍ tiene ficha no se le cambia nada.
     */
    public function testUnSocixSigueViendoElShellDeSuPanel(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $client->request('GET', '/avisos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mi calendario');
        self::assertSelectorTextContains('.csa-page-header', 'Mi área');
        self::assertSelectorTextContains('body', 'Cómo te avisamos');
    }

    public function testUnAvisoSinLeerSaleMarcadoComoNuevo(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $id = $this->givenNotificationForSocix(Notification::KIND_PICKUP_REMINDER, 'TEST El miércoles recoges tu cesta', 'miércoles 3 · Cascorro');

        $client->request('GET', '/avisos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.csa-list__primary', 'TEST El miércoles recoges tu cesta');
        self::assertSelectorTextContains('.csa-list__secondary', 'Cascorro');
        // La marca de no leído: la clase que pinta la barra y la etiqueta.
        self::assertSelectorExists('.csa-list__link--unread');
        self::assertSelectorTextContains('.csa-list__new', 'Nuevo');

        $this->cleanUp($id);
    }

    public function testEntrarEnLaBandejaNoMarcaNadaLeido(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $id = $this->givenNotificationForSocix(Notification::KIND_PICKUP_REMINDER, 'TEST Sigue sin leer');

        $client->request('GET', '/avisos');

        self::assertResponseIsSuccessful();
        self::assertNull(
            $this->em()->find(Notification::class, $id)->getReadAt(),
            'Abrir la bandeja no puede marcar los avisos como leídos.',
        );

        $this->cleanUp($id);
    }

    public function testAbrirUnAvisoLoMarcaLeidoYLlevaASuDestino(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $id = $this->givenNotificationForSocix(Notification::KIND_PICKUP_REMINDER, 'TEST Mañana recoges tu cesta');

        $client->request('GET', '/avisos/' . $id);

        // El aviso de la cesta lleva al panel, la pantalla del "qué me toca".
        self::assertResponseRedirects('/panel');
        self::assertNotNull($this->em()->find(Notification::class, $id)->getReadAt());

        $this->cleanUp($id);
    }

    public function testUnAvisoDeVoluntariadoLlevaAVoluntariado(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $id = $this->givenNotificationForSocix(Notification::KIND_VOLUNTEERING_CALL, 'TEST Falta una persona');

        $client->request('GET', '/avisos/' . $id);

        self::assertResponseRedirects('/panel/voluntariado');

        $this->cleanUp($id);
    }

    public function testNoSePuedeAbrirElAvisoDeOtraPersona(): void
    {
        $client = $this->createPartnerAuthenticatedClient();

        // Un aviso dirigido a OTRA cuenta: el admin de las fixtures sirve.
        $em = $this->em();
        $otra = $em->getRepository(User::class)->loadUserByIdentifier('admin');
        self::assertNotNull($otra, 'Fixtures sin User admin; carga UserFixtures en db_test.');

        $ajena = new Notification($otra, Notification::KIND_PICKUP_REMINDER, 'TEST La cesta de otra persona');
        $em->persist($ajena);
        $em->flush();
        $id = $ajena->getId();

        $client->request('GET', '/avisos/' . $id);

        self::assertResponseStatusCodeSame(403);
        self::assertNull(
            $this->em()->find(Notification::class, $id)->getReadAt(),
            'Un aviso rechazado no puede quedar marcado como leído.',
        );

        $this->cleanUp($id);
    }

    public function testLaCampanitaCuentaLoSinLeerEnElPanel(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $id = $this->givenNotificationForSocix(Notification::KIND_PICKUP_REMINDER, 'TEST Uno sin leer');

        $client->request('GET', '/panel');

        self::assertResponseIsSuccessful();
        // Se comprueba que el globo EXISTE y no su número exacto: la bandeja del
        // socix de las fixtures puede llevar avisos de otro test de la misma
        // tanda, y afirmar "1" haría que el test fallara por el orden de
        // ejecución y no por un fallo de la campanita.
        self::assertSelectorExists('.csa-topbar__bell-count');
        self::assertSelectorExists('.csa-topbar__bell--unread');

        $this->cleanUp($id);
    }

    /**
     * Deja un aviso sin leer en la bandeja del socix de las fixtures.
     *
     * @param string      $kind  una de las constantes Notification::KIND_*
     * @param string      $title el título
     * @param string|null $body  el cuerpo
     *
     * @return int el id del aviso guardado
     */
    private function givenNotificationForSocix(string $kind, string $title, ?string $body = null): int
    {
        $em = $this->em();
        $recipient = $em->getRepository(User::class)->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME);
        self::assertNotNull($recipient, 'Fixtures sin User socix; carga PartnerUserFixtures en db_test.');

        $notification = new Notification($recipient, $kind, $title, $body);
        $em->persist($notification);
        $em->flush();

        return (int) $notification->getId();
    }

    /**
     * Borra un aviso que el test haya creado.
     *
     * La bandeja es acumulativa: un aviso olvidado aquí se quedaría en la de las
     * fixtures y saldría en las pantallas de cualquier test posterior.
     *
     * @param int $id el id del aviso
     */
    private function cleanUp(int $id): void
    {
        $em = $this->em();
        $notification = $em->find(Notification::class, $id);
        if (null !== $notification) {
            $em->remove($notification);
            $em->flush();
        }
    }

    /**
     * Un EntityManager recién sacado del contenedor.
     *
     * @return EntityManagerInterface el manager
     */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
