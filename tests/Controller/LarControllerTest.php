<?php

namespace App\Tests\Controller;

use App\Entity\LarPage;
use App\Entity\LarProject;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests funcionales del panel de gestión del LAR (/gestion/lar).
 *
 * Cubre el round-trip del alta por formulario y la frontera de seguridad del
 * modelo lectura/escritura: ROLE_GESTION_LAR ve el listado pero no muta;
 * ROLE_GESTION_LAR_EDIT sí llega a los endpoints de escritura (el gateo es por
 * método HTTP en access_control sobre ^/gestion/lar).
 *
 * Autocontenido: crea sus datos por EntityManager / formulario y limpia al
 * final, para no depender de fixtures de LAR ni ensuciar db_test.
 */
class LarControllerTest extends AbstractAuthenticatedTest
{
    /**
     * El listado es accesible por un admin (200).
     */
    public function testIndexReachableByAdmin(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/lar');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Alta de un proyecto por el formulario: redirige a su edición y la fila
     * queda persistida.
     */
    public function testCreateProjectRoundTrip(): void
    {
        $client = $this->createAuthenticatedClient();
        $title = 'TEST LAR ' . uniqid();

        $crawler = $client->request('GET', '/gestion/lar/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['lar_project[title]'] = $title;
        $form['lar_project[type]'] = 'campus_rural';
        $form['lar_project[status]'] = LarProject::STATUS_ACTIVE;
        $form['lar_project[summary]'] = 'Resumen de prueba del proyecto LAR.';
        $client->submit($form);

        $this->assertResponseRedirects();

        $project = $this->em()->getRepository(LarProject::class)->findOneBy(['title' => $title]);
        $this->assertNotNull($project, 'El proyecto debería haberse creado.');
        $this->assertNotEmpty($project->getSlug(), 'El slug Gedmo debería generarse.');

        $this->em()->remove($project);
        $this->em()->flush();
    }

    /**
     * Solo lectura: ve el listado (200) pero el firewall corta el alta (POST)
     * con 403.
     */
    public function testReadOnlyRoleCanReadButNotWrite(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_GESTION_LAR']);
        $userId = $user->getId();
        $client->loginUser($user);

        $client->request('GET', '/gestion/lar');
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/gestion/lar/new');
        $this->assertResponseStatusCodeSame(403);

        $this->removeUser($userId);
    }

    /**
     * Escritura: el firewall deja pasar el POST (lo que pase después es cosa del
     * form; lo relevante es que NO es 403).
     */
    public function testEditRoleCanReachWriteEndpoints(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_GESTION_LAR_EDIT']);
        $userId = $user->getId();
        $client->loginUser($user);

        $client->request('POST', '/gestion/lar/new');
        $this->assertResponseStatusCodeSame(200);

        $this->removeUser($userId);
    }

    /**
     * Editar la portada (singleton LarPage): el primer guardado crea la fila con
     * sus ofertas; un segundo guardado con una oferta menos la borra de verdad
     * (orphanRemoval), no la deja huérfana.
     */
    public function testEditPagePersistsAndOffersCascade(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->clearLarPages();

        // Primer guardado: crea la fila con 3 ofertas y un email de coordinación
        // propio (distinto del de fábrica).
        $crawler = $client->request('GET', '/gestion/lar/page');
        $this->assertResponseIsSuccessful();
        $client->request('POST', '/gestion/lar/page', ['lar_page' => [
            '_token' => $crawler->filter('input[name="lar_page[_token]"]')->attr('value'),
            'intro' => '<p>Introducción de prueba.</p>',
            'coordName' => 'Coordinación Test',
            'coordPhone' => '600123123',
            'coordEmail' => 'portada-test@ejemplo.test',
            'offers' => [
                ['title' => 'Oferta A', 'hours' => '10 h', 'body' => 'Cuerpo A'],
                ['title' => 'Oferta B', 'hours' => '20 h', 'body' => 'Cuerpo B'],
                ['title' => 'Oferta C', 'hours' => '30 h', 'body' => 'Cuerpo C'],
            ],
        ]]);
        $this->assertResponseRedirects('/gestion/lar/page');

        $this->em()->clear();
        $page = $this->em()->getRepository(LarPage::class)->findOneBy([]);
        $this->assertNotNull($page, 'La portada debería haberse creado.');
        $this->assertSame('portada-test@ejemplo.test', $page->getCoordEmail());
        $this->assertCount(3, $page->getOffers(), 'Deberían persistir las 3 ofertas.');

        // Segundo guardado con una oferta menos: orphanRemoval deja 2.
        $crawler = $client->request('GET', '/gestion/lar/page');
        $client->request('POST', '/gestion/lar/page', ['lar_page' => [
            '_token' => $crawler->filter('input[name="lar_page[_token]"]')->attr('value'),
            'coordEmail' => 'portada-test@ejemplo.test',
            'offers' => [
                ['title' => 'Oferta A', 'hours' => '10 h', 'body' => 'Cuerpo A'],
                ['title' => 'Oferta B', 'hours' => '20 h', 'body' => 'Cuerpo B'],
            ],
        ]]);
        $this->assertResponseRedirects('/gestion/lar/page');

        $this->em()->clear();
        $page = $this->em()->getRepository(LarPage::class)->findOneBy([]);
        $this->assertCount(2, $page->getOffers(), 'La oferta quitada debe borrarse (orphanRemoval).');

        $this->clearLarPages();
    }

    /**
     * @return EntityManagerInterface
     */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Borra la fila de portada (y sus ofertas, por cascade) para partir/terminar
     * limpio, ya que es un singleton compartido por los tests.
     */
    private function clearLarPages(): void
    {
        $em = $this->em();
        foreach ($em->getRepository(LarPage::class)->findAll() as $page) {
            $em->remove($page);
        }
        $em->flush();
        $em->clear();
    }

    /**
     * Borra un usuario de prueba por id.
     *
     * @param int $userId
     */
    private function removeUser(int $userId): void
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->find($userId);
        if ($user !== null) {
            $em->remove($user);
            $em->flush();
        }
    }
}
