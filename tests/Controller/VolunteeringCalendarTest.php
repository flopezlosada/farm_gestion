<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * El calendario de voluntariado en gestión: qué enseña, cómo se filtra desde la
 * URL y cómo se mueve un turno arrastrándolo.
 *
 * Los filtros en la URL son la promesa de «enlaces prefiltrados» —el calendario
 * de un área, la serie de una tarea— y por eso se prueban uno a uno. Mover por
 * arrastre es un POST sin formulario: se comprueba que respeta la hora, que
 * deja el turno en el mes de destino y que no mueve lo que no debe (al pasado,
 * ni un turno que ya pasó).
 *
 * Autocontenido: fechas en 2099 para que ningún turno real de db_test caiga en
 * el mismo mes.
 */
class VolunteeringCalendarTest extends WebTestCase
{
    private const MONTH = '2099-11';

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
     * El mes pedido enseña sus turnos, y `?tipo=` deja sólo los del área.
     */
    public function testElCalendarioSeFiltraPorArea(): void
    {
        $client = $this->adminWithModuleOn();
        $huerta = $this->makeCategory('Cal huerta');
        $reparto = $this->makeCategory('Cal reparto');
        $this->makeShift('Cal regar', '2099-11-10 09:00:00', $huerta);
        $this->makeShift('Cal descargar', '2099-11-13 18:00:00', $reparto);

        $client->request('GET', '/gestion/voluntariado/calendario?mes='.self::MONTH);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.scal', 'Cal regar');
        $this->assertSelectorTextContains('.scal', 'Cal descargar');

        $client->request('GET', sprintf('/gestion/voluntariado/calendario?mes=%s&tipo=%d', self::MONTH, $huerta->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.scal', 'Cal regar');
        $this->assertSelectorTextNotContains('.scal', 'Cal descargar');
    }

    /**
     * `?tarea=` deja sólo la serie de esa tarea: es el enlace de su ficha.
     */
    public function testElCalendarioSeFiltraPorTarea(): void
    {
        $client = $this->adminWithModuleOn();
        $area = $this->makeCategory('Cal área');
        $regar = $this->makeShift('Cal sólo regar', '2099-11-10 09:00:00', $area);
        $this->makeShift('Cal otra cosa', '2099-11-11 09:00:00', $area);

        $client->request('GET', sprintf('/gestion/voluntariado/calendario?mes=%s&tarea=%d', self::MONTH, $regar->getOffer()->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.scal', 'Cal sólo regar');
        $this->assertSelectorTextNotContains('.scal', 'Cal otra cosa');
    }

    /**
     * Arrastrar a otro día cambia la fecha y conserva la hora y la duración.
     */
    public function testMoverConservaLaHora(): void
    {
        $client = $this->adminWithModuleOn();
        $shift = $this->makeShift('Cal movible', '2099-11-10 09:30:00', $this->makeCategory('Cal mov'), endsAt: '2099-11-10 11:00:00');

        $client->request('POST', sprintf('/gestion/voluntariado/turno/%d/mover', $shift->getId()), [
            'fecha' => '2099-11-17',
            '_csrf_token' => $this->moveToken($client),
        ]);

        $this->assertResponseRedirects('/gestion/voluntariado/calendario?mes=2099-11');

        $moved = $this->reload($shift);
        $this->assertSame('2099-11-17 09:30', $moved->getStartsAt()->format('Y-m-d H:i'));
        $this->assertSame('2099-11-17 11:00', $moved->getEndsAt()->format('Y-m-d H:i'));
        $this->assertTrue($moved->isManual(), 'Movido a mano: la receta ya no lo retira.');
    }

    /**
     * Al pasado no se mueve nada, ni se mueve lo que ya pasó.
     */
    public function testNoSeMueveAlPasadoNiLoQueYaPaso(): void
    {
        $client = $this->adminWithModuleOn();
        $area = $this->makeCategory('Cal quieto');
        $future = $this->makeShift('Cal futuro', '2099-11-10 09:00:00', $area);
        $past = $this->makeShift('Cal pasado', '2001-03-15 10:00:00', $area);
        $token = $this->moveToken($client);

        $client->request('POST', sprintf('/gestion/voluntariado/turno/%d/mover', $future->getId()), [
            'fecha' => '2001-01-01',
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();
        $this->assertSame('2099-11-10', $this->reload($future)->getStartsAt()->format('Y-m-d'));

        $client->request('POST', sprintf('/gestion/voluntariado/turno/%d/mover', $past->getId()), [
            'fecha' => '2099-11-20',
            '_csrf_token' => $token,
        ]);
        $this->assertResponseRedirects();
        $this->assertSame('2001-03-15', $this->reload($past)->getStartsAt()->format('Y-m-d'));
    }

    private function adminWithModuleOn(): KernelBrowser
    {
        $client = static::createClient();
        $user = static::getContainer()->get('doctrine')->getRepository(User::class)->loadUserByIdentifier('admin');
        if (null === $user) {
            throw new \RuntimeException('Fixtures sin User "admin".');
        }
        $client->loginUser($user);
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        return $client;
    }

    /**
     * El token CSRF de mover, tal como lo lee el JavaScript: del atributo de la
     * rejilla.
     */
    private function moveToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/gestion/voluntariado/calendario?mes='.self::MONTH);
        $this->assertResponseIsSuccessful();

        return $crawler->filter('[data-vcal]')->attr('data-vcal-csrf');
    }

    private function makeCategory(string $name): VolunteerCategory
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $category = (new VolunteerCategory())->setName($name);
        $em->persist($category);
        $em->flush();

        return $category;
    }

    private function makeShift(string $title, string $startsAt, VolunteerCategory $category, ?string $endsAt = null): VolunteerShift
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setSlots(2)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->addCategory($category);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime($startsAt));
        if (null !== $endsAt) {
            $shift->setEndsAt(new \DateTime($endsAt));
        }
        $offer->addShift($shift);

        $em->persist($offer);
        $em->persist($shift);
        $em->flush();

        return $shift;
    }

    private function reload(VolunteerShift $shift): VolunteerShift
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(VolunteerShift::class, $shift->getId());
    }
}
