<?php

namespace App\Tests\Controller;

use App\Entity\LarPage;
use App\Entity\LarProject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests funcionales de la cara pública del LAR (/lar).
 *
 * Cubre que la landing renderiza sin login, que el detalle solo es accesible
 * para proyectos publicados (borrador => 404) y que el formulario de contacto
 * dispara el email a la coordinación del LAR.
 *
 * Autocontenido: crea sus proyectos por EntityManager y los borra al final,
 * para no depender de fixtures ni ensuciar db_test.
 */
class LarPublicControllerTest extends WebTestCase
{
    /**
     * La landing pública es anónima y renderiza (200).
     */
    public function testLandingRendersAnonymously(): void
    {
        $client = static::createClient();
        $client->request('GET', '/lar');

        $this->assertResponseIsSuccessful();
    }

    /**
     * El detalle de un proyecto publicado es accesible (200).
     */
    public function testPublishedProjectDetailReachable(): void
    {
        $client = static::createClient();
        $slug = $this->createProject(published: true)['slug'];

        $client->request('GET', '/lar/proyecto/' . $slug);
        $this->assertResponseIsSuccessful();

        $this->removeProjectBySlug($slug);
    }

    /**
     * El detalle de un proyecto en borrador (published=false) devuelve 404:
     * no debe filtrarse a la web hasta que se publique.
     */
    public function testDraftProjectDetailReturns404(): void
    {
        $client = static::createClient();
        $slug = $this->createProject(published: false)['slug'];

        $client->request('GET', '/lar/proyecto/' . $slug);
        $this->assertResponseStatusCodeSame(404);

        $this->removeProjectBySlug($slug);
    }

    /**
     * Un slug inexistente devuelve 404.
     */
    public function testUnknownSlugReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/lar/proyecto/no-existe-' . uniqid());

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * El formulario de contacto válido envía un email a la coordinación del LAR
     * y redirige de vuelta a la landing.
     */
    public function testContactFormSendsEmail(): void
    {
        $client = static::createClient();

        // El limiter contact_form (sliding_window) persiste entre tests en
        // cache.app y lo comparte el contacto general; ContactFormRateLimitTest
        // lo agota con 4 envíos desde 127.0.0.1. Lo reseteamos para esta IP para
        // que este test sea determinista sea cual sea el orden de ejecución.
        static::getContainer()->get('limiter.contact_form')->create('127.0.0.1')->reset();
        // Sin portada guardada: el destino cae al buzón de fábrica (larcsa@).
        $this->clearLarPages();

        $crawler = $client->request('GET', '/lar');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enviar solicitud')->form([
            'lar_contact[name]' => 'Colegio de prueba',
            'lar_contact[email]' => 'contacto@ejemplo.test',
            'lar_contact[requestType]' => 'visita_pedagogica',
            'lar_contact[body]' => 'Nos gustaría visitar el LAR con un grupo de alumnado.',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/lar');
        $this->assertEmailCount(1);
        $this->assertEmailAddressContains(
            $this->getMailerMessage(),
            'To',
            'larcsa@csavegadejarama.org'
        );
    }

    /**
     * Con una portada guardada, el email del formulario va al coordEmail de BBDD,
     * NO al fallback. Se prueba con un coordEmail DISTINTO del de fábrica para que
     * el test detecte de verdad si el destino sigue al dato o quedó hardcodeado.
     */
    public function testContactEmailFollowsCoordEmailFromDb(): void
    {
        $client = static::createClient();

        // Portada persistida con un buzón de coordinación propio.
        $this->clearLarPages();
        $em = $this->em();
        $page = LarPage::createWithDefaults();
        $page->setCoordEmail('destino-real@ejemplo.test');
        $em->persist($page);
        $em->flush();

        static::getContainer()->get('limiter.contact_form')->create('127.0.0.1')->reset();

        $crawler = $client->request('GET', '/lar');
        $form = $crawler->selectButton('Enviar solicitud')->form([
            'lar_contact[name]' => 'Grupo de prueba',
            'lar_contact[email]' => 'quien@ejemplo.test',
            'lar_contact[requestType]' => 'visita_pedagogica',
            'lar_contact[body]' => 'Consulta de prueba con portada guardada.',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/lar');
        $this->assertEmailCount(1);
        $this->assertEmailAddressContains($this->getMailerMessage(), 'To', 'destino-real@ejemplo.test');

        $this->clearLarPages();
    }

    /**
     * Crea un proyecto LAR persistido y devuelve sus datos (incluido el slug
     * generado por Gedmo tras el flush).
     *
     * @param bool $published
     * @return array{id: int, slug: string}
     */
    private function createProject(bool $published): array
    {
        $em = $this->em();
        $project = (new LarProject())
            ->setTitle('TEST LAR público ' . uniqid())
            ->setType('campus_rural')
            ->setSummary('Resumen de prueba.')
            ->setStatus(LarProject::STATUS_ACTIVE)
            ->setPublished($published);
        $em->persist($project);
        $em->flush();

        return ['id' => $project->getId(), 'slug' => $project->getSlug()];
    }

    /**
     * Borra un proyecto de prueba por slug.
     *
     * @param string $slug
     */
    private function removeProjectBySlug(string $slug): void
    {
        $em = $this->em();
        $project = $em->getRepository(LarProject::class)->findOneBy(['slug' => $slug]);
        if ($project !== null) {
            $em->remove($project);
            $em->flush();
        }
    }

    /**
     * Borra la portada (singleton LarPage) y sus ofertas por cascade, re-obtenida
     * del manager actual para no operar sobre una referencia detached tras los
     * requests del cliente. Deja db_test limpio de portada.
     */
    private function clearLarPages(): void
    {
        $em = $this->em();
        foreach ($em->getRepository(LarPage::class)->findAll() as $page) {
            $em->remove($page);
        }
        $em->flush();
    }

    /**
     * @return EntityManagerInterface
     */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
