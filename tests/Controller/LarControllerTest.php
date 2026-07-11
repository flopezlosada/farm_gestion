<?php

namespace App\Tests\Controller;

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
        $form['lar_project[position]'] = '0';
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
     * @return EntityManagerInterface
     */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
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
