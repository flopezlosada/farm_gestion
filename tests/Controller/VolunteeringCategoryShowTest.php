<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La ficha de un área de voluntariado y el atajo para cambiar el estado de sus
 * tareas.
 *
 * Lo que se prueba aquí no es que las pantallas carguen —eso lo diría cualquier
 * smoke— sino las tres cosas que pueden romperse EN SILENCIO:
 *
 *  1. Que el histórico del área traiga la actividad de sus tareas. Es el arreglo
 *     de un bloque que se veía siempre vacío, y si se rompiera volvería a verse
 *     vacío sin dar ningún error.
 *  2. Que el atajo de estado no acepte cualquier cadena. `setStatus()` traga lo
 *     que le den y la validación de la entidad no corre en ese camino: un valor
 *     cualquiera dejaría la tarea en un estado que ninguna consulta reconoce y
 *     desaparecería de todas las pantallas.
 *  3. Que el filtro por área de la pantalla de actividad no amplíe permisos. Un
 *     filtro que abre de más no falla, sólo enseña lo que no debía.
 */
class VolunteeringCategoryShowTest extends WebTestCase
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
     * La ficha enseña las tareas del área y NO las de otra.
     */
    public function testLaFichaEnsenaLasTareasDeSuAreaYNoLasDeOtra(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $mia = $this->makeCategory($em, 'Ficha Mía');
        $ajena = $this->makeCategory($em, 'Ficha Ajena');
        $this->makeOffer($em, 'Tarea de la ficha propia', $mia);
        $this->makeOffer($em, 'Tarea de la ficha ajena', $ajena);
        $em->flush();

        $client->request('GET', '/gestion/voluntariado/categorias/' . $mia->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Tarea de la ficha propia', $client->getResponse()->getContent());
        $this->assertStringNotContainsString('Tarea de la ficha ajena', $client->getResponse()->getContent());
    }

    /**
     * El histórico del área incluye lo que pasa DENTRO de sus tareas.
     *
     * Es el motivo de que este bloque exista. Antes sólo traía lo que le había
     * pasado al área en sí, y en un área que nadie ha tocado eso son cero filas:
     * se veía siempre vacío aunque dentro hubiera actividad.
     */
    public function testElHistoricoDelAreaIncluyeLaActividadDeSusTareas(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Ficha Con Historia');
        $offer = $this->makeOffer($em, 'Tarea con rastro', $area);

        $em->persist(
            (new VolunteerEvent())
                ->setType(VolunteerEvent::TYPE_OFFER_CREATED)
                ->setOffer($offer)
                ->setActor(VolunteerEvent::ACTOR_SYSTEM)
        );
        $em->flush();

        $client->request('GET', '/gestion/voluntariado/categorias/' . $area->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            VolunteerEvent::LABELS[VolunteerEvent::TYPE_OFFER_CREATED],
            $client->getResponse()->getContent()
        );
    }

    /**
     * El atajo publica un borrador.
     */
    public function testElAtajoPublicaUnBorrador(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Ficha Publicar');
        $offer = $this->makeOffer($em, 'Borrador a publicar', $area, VolunteerOffer::STATUS_DRAFT);
        $em->flush();

        $client->request('POST', '/gestion/voluntariado/' . $offer->getId() . '/estado', [
            '_csrf_token' => $this->token($client, 'volunteering_status'),
            'status' => VolunteerOffer::STATUS_PUBLISHED,
            'from_category' => $area->getId(),
        ]);

        $this->assertResponseRedirects('/gestion/voluntariado/categorias/' . $area->getId());

        $em->refresh($offer);
        $this->assertSame(VolunteerOffer::STATUS_PUBLISHED, $offer->getStatus());
    }

    /**
     * Un estado que no existe se rechaza y la tarea se queda como estaba.
     *
     * `setStatus()` acepta cualquier cadena, así que sin la lista blanca del
     * controller un POST a mano dejaría la tarea en un estado que ninguna
     * consulta reconoce: no daría error, simplemente desaparecería de todas las
     * pantallas.
     */
    public function testUnEstadoInventadoNoTocaLaTarea(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Ficha Estado Falso');
        $offer = $this->makeOffer($em, 'Tarea intacta', $area, VolunteerOffer::STATUS_PUBLISHED);
        $em->flush();

        $client->request('POST', '/gestion/voluntariado/' . $offer->getId() . '/estado', [
            '_csrf_token' => $this->token($client, 'volunteering_status'),
            'status' => 'archivada-por-la-cara',
            'from_category' => $area->getId(),
        ]);

        $em->refresh($offer);
        $this->assertSame(VolunteerOffer::STATUS_PUBLISHED, $offer->getStatus());
    }

    /**
     * Sin token, el atajo no cambia nada.
     *
     * Cambia datos y avisa por correo a quien se hubiera apuntado, así que tiene
     * que estar cerrado a un POST desde fuera.
     */
    public function testSinTokenElAtajoNoCambiaNada(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Ficha Sin Token');
        $offer = $this->makeOffer($em, 'Tarea sin token', $area, VolunteerOffer::STATUS_PUBLISHED);
        $em->flush();

        $client->request('POST', '/gestion/voluntariado/' . $offer->getId() . '/estado', [
            'status' => VolunteerOffer::STATUS_CANCELLED,
            'from_category' => $area->getId(),
        ]);

        $em->refresh($offer);
        $this->assertSame(VolunteerOffer::STATUS_PUBLISHED, $offer->getStatus());
    }

    /**
     * El formulario de edición ya NO pinta el histórico.
     *
     * Se comprueba porque el bloque se movió a la ficha, y dejarlo en los dos
     * sitios era justamente el problema: en el formulario salía vacío.
     */
    public function testEditarNoPintaElHistorico(): void
    {
        $client = $this->adminClient();
        $em = $this->em();

        $area = $this->makeCategory($em, 'Ficha Editar Limpio');
        $em->flush();

        $client->request('GET', '/gestion/voluntariado/categorias/' . $area->getId() . '/editar');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('vol-history', $client->getResponse()->getContent());
    }

    /**
     * Pedir por URL la actividad de un área que no coordinas no la abre.
     *
     * ES EL CASO DE SEGURIDAD DE ESTE FICHERO. El filtro por área se interseca
     * con el alcance de quien mira; si lo sustituyera, cualquier cuenta que
     * coordine un área podría leer la actividad de todas las demás cambiando un
     * número en la barra de direcciones.
     */
    public function testPedirLaActividadDeUnAreaAjenaNoLaAbre(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $mia = $this->makeCategory($em, 'Actividad Mía');
        $ajena = $this->makeCategory($em, 'Actividad Ajena');
        $offer = $this->makeOffer($em, 'Tarea de área ajena', $ajena);

        $em->persist(
            (new VolunteerEvent())
                ->setType(VolunteerEvent::TYPE_OFFER_CREATED)
                ->setOffer($offer)
                ->setActor(VolunteerEvent::ACTOR_SYSTEM)
        );

        // Coordina SÓLO "Actividad Mía": de ahí le viene el rol, sin ningún
        // permiso global escrito en la columna.
        $user = (new User())
            ->setUsername('coord-' . uniqid())
            ->setEmail('coord-' . uniqid() . '@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true);
        $mia->addCoordinator($user);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/gestion/voluntariado/actividad?area=' . $ajena->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Tarea de área ajena', $client->getResponse()->getContent());
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

    private function token(KernelBrowser $client, string $id): string
    {
        return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function makeCategory(EntityManagerInterface $em, string $name): VolunteerCategory
    {
        // Con sufijo: el nombre es único en la tabla y estos tests corren sobre
        // una db_test que no se vacía entre ejecuciones.
        $category = (new VolunteerCategory())->setName($name . ' ' . uniqid());
        $em->persist($category);

        return $category;
    }

    private function makeOffer(
        EntityManagerInterface $em,
        string $title,
        VolunteerCategory $category,
        string $status = VolunteerOffer::STATUS_PUBLISHED,
    ): VolunteerOffer {
        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setStartsAt(new \DateTime('+3 days'))
            ->setStatus($status)
            ->addCategory($category);
        $em->persist($offer);

        return $offer;
    }
}
