<?php

namespace App\Tests\Controller;

use App\Entity\Partner;
use App\Service\Notification\IncompleteProfileNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El listado de fichas con datos sin rellenar: la pantalla a la que lleva el aviso
 * que recibe quien coordina socixs.
 *
 * Lo que protege, además de que cargue:
 *  - que la lista de la pantalla y el número del aviso salgan del MISMO sitio. Si
 *    se separaran, el aviso diría doce, la pantalla enseñaría nueve y quien lo
 *    abriera dejaría de fiarse de los dos;
 *  - que la ruta no la capture ninguna de las que llevan parámetro en el mismo
 *    controlador (`/{id}`, `/{type}/list/`). Es un fallo que no avisa: en vez de
 *    un error se llega a otra pantalla.
 */
class IncompleteProfilesScreenTest extends AbstractAuthenticatedTest
{
    public function testLaPantallaCargaParaQuienCoordinaSocixs(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/incomplete-profiles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.csa-page-header h1', 'Fichas con datos sin rellenar');
    }

    /**
     * Sin permiso de socixs no se entra: en la lista van nombre, correo y qué le
     * falta a cada persona.
     */
    public function testSinPermisoDeSocixsNoSeEntra(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_GRANJA']));

        $client->request('GET', '/gestion/partner/incomplete-profiles');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Una ficha a medias sale en la pantalla con lo que le falta, y la sección de
     * "lo rellena elle" separada de la de administración: es lo que le dice a quien
     * coordina si le toca llamar o esperar.
     */
    public function testUnaFichaAMediasSaleConLoQueLeFalta(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em();

        $nombre = 'TESTINCOMPLETO' . uniqid();
        $partner = (new Partner())
            ->setname($nombre)
            ->setSurname('Sin Datos')
            ->setStatus(Partner::STATUS_ACTIVO)
            // Ni DNI, ni dirección, ni provincia, ni municipio, ni teléfono.
            ->setemail('');
        $em->persist($partner);
        $em->flush();
        $id = $partner->getId();

        $client->request('GET', '/gestion/partner/incomplete-profiles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $nombre);
        // Lo que sólo cambia administración se pinta aparte de lo que rellena elle.
        self::assertSelectorTextContains('body', 'sin correo');

        $em = $this->em();
        $em->remove($em->find(Partner::class, $id));
        $em->flush();
    }

    /**
     * La pantalla cuenta lo mismo que contaría el aviso. Es la aserción que impide
     * que las dos cifras se separen.
     */
    public function testLaPantallaEnseniaLoMismoQueCuentaElAviso(): void
    {
        $client = $this->createAuthenticatedClient();

        $crawler = $client->request('GET', '/gestion/partner/incomplete-profiles');
        self::assertResponseIsSuccessful();

        $enPantalla = $crawler->filter('.csa-table tbody tr')->count();
        $enElAviso = \count(static::getContainer()->get(IncompleteProfileNotifier::class)->incompleteProfiles());

        self::assertSame($enElAviso, $enPantalla);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
