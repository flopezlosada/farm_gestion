<?php

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Setting;
use App\Entity\User;

/**
 * Las novedades de la web: la pantalla de administración, el botón que las
 * cuenta y la página que ve quien entra.
 *
 * LO QUE PROTEGE, además de que las pantallas carguen:
 *  - que la página de socixs NO enseñe lo que aún no se ha contado. Si lo
 *    enseñara, la novedad estaría publicada antes de que nadie pulsara nada y
 *    el botón dejaría de decidir cuándo se cuenta;
 *  - que contarlo mueva el puntero, porque es lo único que impide que el mismo
 *    anuncio salga dos veces;
 *  - que lo cuente por las tres vías a quien corresponde.
 *
 * EL PUNTERO SE LIMPIA AL TERMINAR. Es una fila compartida por toda la base de
 * test: si un test lo deja movido, el siguiente no encuentra nada pendiente y
 * pasa por el motivo equivocado.
 */
class NewsControllerTest extends AbstractAuthenticatedTest
{
    private const POINTER = 'news.announced_through';

    protected function tearDown(): void
    {
        // El kernel se reinicia en cada petición, así que no vale guardarse el
        // objeto: se busca de nuevo por su nombre.
        if (static::$booted) {
            $em = static::getContainer()->get('doctrine')->getManager();
            $setting = $em->getRepository(Setting::class)->findOneBy(['name' => self::POINTER]);
            if ($setting !== null) {
                $em->remove($setting);
                $em->flush();
            }
        }

        parent::tearDown();
    }

    public function testLaPantallaDeGestionCargaParaElAdmin(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/novedades');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.csa-page-header h1', 'Novedades de la web');
    }

    /**
     * Contarlo escribe a toda la asociación, así que no basta con ser del
     * equipo de gestión.
     */
    public function testSinSerAdminNoSeEntraAGestion(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_SOCIXS']));

        $client->request('GET', '/gestion/novedades');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Lo pendiente sale en la pantalla de administración —es lo que hay que
     * revisar antes de mandarlo— y NO sale en la página de socixs.
     */
    public function testLoPendienteSoloSeVeEnGestion(): void
    {
        $client = $this->createAuthenticatedClient();

        $crawler = $client->request('GET', '/gestion/novedades');
        $titulo = $crawler->filter('.csa-list__primary')->first()->text();
        self::assertNotSame('', $titulo);

        $client->request('GET', '/novedades');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $titulo);
    }

    /**
     * Sin el token no se manda nada: si un enlace o una recarga pudieran
     * dispararlo, el anuncio saldría sin que nadie lo decidiera.
     */
    public function testSinTokenNoSeCuentaNada(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => 'inventado']);

        self::assertResponseRedirects('/gestion/novedades');
        self::assertSame(0, $this->pointer());
    }

    /**
     * El camino completo: se cuenta, sale el correo, queda el aviso en la
     * bandeja y el puntero se mueve para que no vuelva a salir.
     */
    public function testContarloAvisaYMueveElPuntero(): void
    {
        $client = $this->createAuthenticatedClient();
        $socix = $this->crearSocixConCuenta();

        $crawler = $client->request('GET', '/gestion/novedades');
        $token = $crawler->filter('#confirm-announce-news input[name="_token"]')->attr('value');

        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => $token]);

        self::assertResponseRedirects('/gestion/novedades');
        self::assertGreaterThan(0, $this->pointer());

        $em = static::getContainer()->get('doctrine')->getManager();
        $avisos = $em->getRepository(Notification::class)->findBy([
            'recipient' => $em->getRepository(User::class)->find($socix['user']),
            'kind' => Notification::KIND_NEWS,
        ]);
        self::assertCount(1, $avisos, 'Al socix con cuenta le tiene que quedar la copia en la bandeja.');

        // El correo se comprueba por destinatario y no por la cuenta total: en
        // la base de test hay más socixs activxs, y atar el test a cuántos son
        // lo rompería cada vez que alguien toque las fixtures.
        $destinatarios = [];
        foreach ($this->getMailerMessages() as $message) {
            foreach ($message->getTo() as $address) {
                $destinatarios[] = $address->getAddress();
            }
        }
        self::assertContains($socix['email'], $destinatarios);

        $this->limpiar($socix);
    }

    /**
     * Contarlo dos veces no manda nada la segunda: el puntero ya pasó por
     * encima. Es la garantía de que un doble clic no duplica el correo.
     */
    public function testContarloDosVecesNoRepiteElAviso(): void
    {
        $client = $this->createAuthenticatedClient();

        $crawler = $client->request('GET', '/gestion/novedades');
        $token = $crawler->filter('#confirm-announce-news input[name="_token"]')->attr('value');
        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => $token]);

        $primero = $this->pointer();

        $crawler = $client->request('GET', '/gestion/novedades');
        // Sin nada pendiente la pantalla ya no pinta el diálogo, así que el
        // token se pide aparte: se está probando el servicio, no la pantalla.
        self::assertCount(0, $crawler->filter('#confirm-announce-news'));

        $client->request('POST', '/gestion/novedades/anunciar', [
            '_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('announce_news')->getValue(),
        ]);

        self::assertSame($primero, $this->pointer());
    }

    /**
     * Un socix activo con correo y con cuenta de acceso, que es quien recibe
     * por las tres vías.
     *
     * @return array{partner: int, user: int, email: string}
     */
    private function crearSocixConCuenta(): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $suffix = uniqid();
        $email = 'novedades-' . $suffix . '@example.test';

        $partner = (new Partner())
            ->setname('TESTNOVEDADES' . $suffix)
            ->setSurname('Con Cuenta')
            ->setStatus(Partner::STATUS_ACTIVO)
            ->setemail($email);
        $em->persist($partner);

        $user = (new User())
            ->setUsername('novedades-' . $suffix)
            ->setEmail($email)
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setPartner($partner);
        $em->persist($user);
        $em->flush();

        return ['partner' => $partner->getId(), 'user' => $user->getId(), 'email' => $email];
    }

    /**
     * @param array{partner: int, user: int, email: string} $socix
     */
    private function limpiar(array $socix): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->find($socix['user']);
        foreach ($em->getRepository(Notification::class)->findBy(['recipient' => $user]) as $aviso) {
            $em->remove($aviso);
        }
        $em->remove($user);
        $em->remove($em->getRepository(Partner::class)->find($socix['partner']));
        $em->flush();
    }

    /**
     * El valor del puntero de lo ya anunciado, 0 si todavía no hay fila.
     */
    private function pointer(): int
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        return (int) ($em->getRepository(Setting::class)->findOneBy(['name' => self::POINTER])?->getValue() ?? 0);
    }
}
