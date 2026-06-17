<?php

namespace App\Tests\Controller;

use App\Entity\Helper;
use App\Entity\HelperSource;
use App\Entity\HostingCapacity;
use App\Entity\Stay;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Smoke tests funcionales del módulo albergue. Los índices ya los barre
 * GestionSmokeRoutesTest; aquí se cubre lo que ese barrido no ve:
 *   - el round-trip del CRUD de voluntarios por formulario, y
 *   - el GUARD DE AFORO de extremo a extremo (HTTP → controller → servicio):
 *     una estancia confirmada que satura el aforo se rechaza sin persistir.
 *
 * Autocontenido: crea sus datos por EntityManager, conduce los formularios con
 * el crawler (CSRF incluido) y limpia al final, para no depender de fixtures de
 * albergue ni ensuciar db_test. Usa un mes lejano (2099-06) para no chocar con
 * ningún dato.
 *
 * Módulo albergue, Fase 1 (2026-06-17).
 */
class AlbergueControllerTest extends AbstractAuthenticatedTest
{
    /** Mes aislado para las pruebas de aforo (lejos de cualquier dato real). */
    private const TEST_YEAR = 2099;
    private const TEST_MONTH = 6;

    /**
     * Alta de un voluntario por el formulario: redirige a su ficha y la fila
     * queda persistida; la ficha responde 200.
     */
    public function testCreateHelperRoundTrip(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em();
        $source = $this->createSource($em, 'TEST WWOOF ' . uniqid());
        $sourceId = $source->getId();
        $name = 'TEST Helper ' . uniqid();

        $crawler = $client->request('GET', '/gestion/albergue/helpers/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['helper[name]'] = $name;
        $form['helper[source]'] = (string) $sourceId;
        $client->submit($form);

        $this->assertResponseRedirects();

        $helper = $this->em()->getRepository(Helper::class)->findOneBy(['name' => $name]);
        $this->assertNotNull($helper, 'El voluntario debería haberse creado.');

        $client->request('GET', sprintf('/gestion/albergue/helpers/%d', $helper->getId()));
        $this->assertResponseIsSuccessful();

        // Limpieza.
        $em = $this->em();
        $em->remove($em->getRepository(Helper::class)->find($helper->getId()));
        $em->remove($em->getRepository(HelperSource::class)->find($sourceId));
        $em->flush();
    }

    /**
     * El calendario de ocupación responde 200, tanto en la ventana por defecto
     * como navegando a un mes concreto. No lo cubre GestionSmokeRoutesTest (la
     * ruta no termina en "/").
     */
    public function testTimelineRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/albergue/timeline');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/gestion/albergue/timeline?from=2099-06');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Guard de aforo: con 1 plaza el mes, una primera estancia confirmada cabe;
     * una segunda confirmada que solapa se rechaza (no redirige, no persiste) y
     * la respuesta avisa del conflicto.
     */
    public function testStayGuardRejectsOverbooking(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em();

        $source = $this->createSource($em, 'TEST source ' . uniqid());
        $helperA = $this->createHelper($em, 'TEST A ' . uniqid(), $source);
        $helperB = $this->createHelper($em, 'TEST B ' . uniqid(), $source);
        $capacity = (new HostingCapacity())
            ->setYear(self::TEST_YEAR)
            ->setMonth(self::TEST_MONTH)
            ->setOpen(true)
            ->setCapacity(1);
        $em->persist($capacity);
        $em->flush();

        $sourceId = $source->getId();
        $helperAId = $helperA->getId();
        $helperBId = $helperB->getId();
        $capacityId = $capacity->getId();

        // Primera estancia confirmada: 1 cama sobre aforo 1 → cabe.
        $this->submitStay($client, $helperAId, '2099-06-10', '2099-06-15', Stay::STATUS_CONFIRMED);
        $this->assertResponseRedirects(sprintf('/gestion/albergue/helpers/%d', $helperAId));

        $confirmed = $this->countConfirmedStays();
        $this->assertSame(1, $confirmed, 'La primera estancia confirmada debería haberse guardado.');

        // Segunda confirmada que solapa: 2 camas sobre aforo 1 → rechazada.
        $crawler = $this->submitStay($client, $helperBId, '2099-06-12', '2099-06-18', Stay::STATUS_CONFIRMED);

        $this->assertResponseStatusCodeSame(200, 'El guard debería re-renderizar el form, no redirigir.');
        $this->assertSame(1, $this->countConfirmedStays(), 'La estancia que satura NO debería persistirse.');
        $this->assertStringContainsString('No cabe', $crawler->filter('body')->text(), 'Debería avisar de que no cabe.');

        // La MISMA segunda estancia como solicitada sí entra (no ocupa aforo).
        $this->submitStay($client, $helperBId, '2099-06-12', '2099-06-18', Stay::STATUS_REQUESTED);
        $this->assertResponseRedirects(sprintf('/gestion/albergue/helpers/%d', $helperBId));

        // Limpieza (estancias → helpers → capacity → source).
        $em = $this->em();
        foreach ($em->getRepository(Stay::class)->findBy(['helper' => [$helperAId, $helperBId]]) as $stay) {
            $em->remove($stay);
        }
        $em->flush();
        $em->remove($em->getRepository(Helper::class)->find($helperAId));
        $em->remove($em->getRepository(Helper::class)->find($helperBId));
        $em->remove($em->getRepository(HostingCapacity::class)->find($capacityId));
        $em->remove($em->getRepository(HelperSource::class)->find($sourceId));
        $em->flush();
    }

    /**
     * Envía el formulario de nueva estancia de un voluntario con las fechas y el
     * estado dados, y devuelve el crawler de la respuesta.
     */
    private function submitStay(
        KernelBrowser $client,
        int $helperId,
        string $arrival,
        string $departure,
        string $status,
    ): Crawler {
        $crawler = $client->request('GET', sprintf('/gestion/albergue/stays/new/%d', $helperId));
        $form = $crawler->filter('form')->form();
        $form['stay[arrivalDate]'] = $arrival;
        $form['stay[departureDate]'] = $departure;
        $form['stay[status]'] = $status;

        return $client->submit($form);
    }

    /**
     * Cuenta las estancias confirmadas del mes de prueba (aislado).
     */
    private function countConfirmedStays(): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Stay::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.arrivalDate >= :from')
            ->andWhere('s.arrivalDate < :to')
            ->setParameter('status', Stay::STATUS_CONFIRMED)
            ->setParameter('from', new \DateTimeImmutable('2099-06-01'))
            ->setParameter('to', new \DateTimeImmutable('2099-07-01'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Crea y persiste una procedencia.
     */
    private function createSource(EntityManagerInterface $em, string $name): HelperSource
    {
        $source = (new HelperSource())->setName($name);
        $em->persist($source);
        $em->flush();

        return $source;
    }

    /**
     * Crea y persiste un voluntario con la procedencia dada.
     */
    private function createHelper(EntityManagerInterface $em, string $name, HelperSource $source): Helper
    {
        $helper = (new Helper())->setName($name)->setSource($source);
        $em->persist($helper);
        $em->flush();

        return $helper;
    }

    /**
     * EntityManager fresco del contenedor (evita identity map obsoleto entre
     * peticiones HTTP del cliente).
     */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
