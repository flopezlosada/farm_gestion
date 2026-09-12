<?php

namespace App\Tests\Controller;

use App\Entity\Setting;

/**
 * Las novedades de la web: la pantalla de administración, el botón que las pide
 * y la página que ve quien entra.
 *
 * LO QUE PROTEGE, además de que las pantallas carguen:
 *  - que el botón NO envíe nada desde la petición web. Son ~130 correos: dentro
 *    de un POST el proceso se queda sin tiempo a mitad de lote y nadie sabe a
 *    quién le llegó. El botón sólo deja anotado hasta dónde contar, y el envío
 *    es cosa del planificador ({@see \App\Tests\Command\AnnounceNewsCommandTest});
 *  - que la página de socixs no enseñe lo que aún no se ha contado. Si lo
 *    enseñara, la novedad estaría publicada antes de que nadie pulsara nada;
 *  - que pedirlo dos veces no encadene dos anuncios.
 *
 * LOS PUNTEROS SE LIMPIAN AL TERMINAR. Son filas compartidas por toda la base de
 * test: si un test deja uno movido, el siguiente no encuentra nada pendiente y
 * pasa por el motivo equivocado.
 */
class NewsControllerTest extends AbstractAuthenticatedTest
{
    private const REQUESTED = 'news.requested_through';

    private const ANNOUNCED = 'news.announced_through';

    protected function tearDown(): void
    {
        // Se limpia en tearDown y no al final del test: si una aserción falla a
        // mitad, PHPUnit lanza y una limpieza escrita en la última línea no se
        // ejecutaría nunca, dejando el puntero movido para los demás. El kernel
        // se reinicia en cada petición, así que las filas se buscan de nuevo por
        // su nombre en vez de guardarse el objeto.
        if (static::$booted) {
            $em = static::getContainer()->get('doctrine')->getManager();
            foreach ([self::REQUESTED, self::ANNOUNCED] as $name) {
                $setting = $em->getRepository(Setting::class)->findOneBy(['name' => $name]);
                if ($setting !== null) {
                    $em->remove($setting);
                }
            }
            $em->flush();
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
     * Pedir que se cuente algo escribe a toda la asociación, así que no basta
     * con ser del equipo de gestión.
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
     * Sin el token no se pide nada: si un enlace o una recarga pudieran
     * dispararlo, el anuncio se pondría en marcha sin que nadie lo decidiera.
     */
    public function testSinTokenNoSePideNada(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => 'inventado']);

        self::assertResponseRedirects('/gestion/novedades');
        self::assertSame(0, $this->pointer(self::REQUESTED));
    }

    /**
     * El botón deja la tanda pedida y NO la manda: mover el puntero de lo
     * anunciado es cosa del planificador. Sin esta separación, el POST cargaría
     * con el lote entero de correos.
     */
    public function testElBotonPideLaTandaPeroNoLaManda(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => $this->token($client)]);

        self::assertResponseRedirects('/gestion/novedades');
        self::assertGreaterThan(0, $this->pointer(self::REQUESTED), 'La tanda tiene que quedar pedida.');
        self::assertSame(0, $this->pointer(self::ANNOUNCED), 'El botón no puede dar la tanda por contada: no ha mandado nada.');
        self::assertCount(0, $this->getMailerMessages(), 'El envío no puede salir de la petición web.');
    }

    /**
     * Pedirlo dos veces deja la misma tanda pedida, no dos. Es lo que hace que
     * un doble clic o dos pestañas abiertas no puedan duplicar el anuncio.
     */
    public function testPedirloDosVecesNoEncadenaDosAnuncios(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => $this->token($client)]);
        $primero = $this->pointer(self::REQUESTED);

        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => $this->token($client)]);

        self::assertSame($primero, $this->pointer(self::REQUESTED));
    }

    /**
     * Con la tanda ya pedida, la pantalla lo dice en vez de quedarse igual que
     * antes de pulsar: entre el botón y la pasada del reloj puede pasar una
     * hora, y sin este aviso parecería que no ha ocurrido nada.
     */
    public function testLaPantallaEnsenaLoQueEstaEnCamino(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/gestion/novedades/anunciar', ['_token' => $this->token($client)]);
        $client->request('GET', '/gestion/novedades');

        self::assertSelectorTextContains('.csa-card__title', 'En camino');
    }

    /**
     * El token de la acción.
     *
     * Se pide al gestor de tokens y no se lee del formulario a propósito: en
     * cuanto la tanda está pedida la pantalla ya no pinta el diálogo, así que
     * leerlo de ahí devolvería una cadena vacía en la segunda pulsación y el
     * test pasaría por el motivo equivocado —fallo de token, no idempotencia—.
     */
    private function token(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken('announce_news')
            ->getValue();
    }

    /**
     * El valor de un puntero, 0 si todavía no hay fila.
     */
    private function pointer(string $name): int
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        return (int) ($em->getRepository(Setting::class)->findOneBy(['name' => $name])?->getValue() ?? 0);
    }
}
