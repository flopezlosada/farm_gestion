<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * El calendario del socix distingue lo de sus áreas del resto: lo suyo se
 * resalta, lo demás se apaga, y con `?solo=mias` lo demás ni sale. Va a un mes
 * de 2099 para que ningún turno real de db_test caiga en la misma rejilla.
 *
 * Es SU calendario de voluntariado: quien marcó huerta no tiene por qué leer
 * el reparto de tres nodos por encima de lo que le interesa.
 */
class PanelVolunteeringCalendarAreasTest extends AbstractPartnerAuthenticatedTest
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

    public function testLoDeMisAreasSeResaltaYElRestoSeApaga(): void
    {
        $client = $this->socixWithModuleOn();
        [$mine, $other] = $this->prepareAreas();
        $this->makeShift('Areas mía', $mine);
        $this->makeShift('Areas ajena', $other);

        $crawler = $client->request('GET', '/panel/voluntariado/calendario?mes='.self::MONTH);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Areas mía', $crawler->filter('.scal-chip--mine')->text());
        $this->assertStringContainsString('Areas ajena', $crawler->filter('.scal-chip--dim')->text());
        // Apagado no es prohibido: la ficha ajena conserva su casilla.
        $this->assertGreaterThan(0, $crawler->filter('.scal-chip--dim input.scal-chip__check')->count());
    }

    public function testConSoloMiasElRestoNiSale(): void
    {
        $client = $this->socixWithModuleOn();
        [$mine, $other] = $this->prepareAreas();
        $this->makeShift('Areas mía sola', $mine);
        $this->makeShift('Areas ajena fuera', $other);

        $client->request('GET', sprintf('/panel/voluntariado/calendario?mes=%s&solo=mias', self::MONTH));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.scal', 'Areas mía sola');
        $this->assertSelectorTextNotContains('.scal', 'Areas ajena fuera');
    }

    /**
     * El socix no ve lo anulado, y las plazas van en palabras, nunca «1/3».
     */
    public function testNoVeLoAnuladoYLeeLasPlazasEnPalabras(): void
    {
        $client = $this->socixWithModuleOn();
        [$mine] = $this->prepareAreas();
        $this->makeShift('Areas con hueco', $mine);
        $this->makeShift('Areas anulada', $mine)->cancel('festivo');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/panel/voluntariado/calendario?mes='.self::MONTH);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.scal', 'Areas con hueco');
        $this->assertSelectorTextNotContains('.scal', 'Areas anulada');
        $this->assertStringContainsString('Quedan 3 plazas', $crawler->filter('.scal')->text());
        $this->assertStringNotContainsString('0/3', $crawler->filter('.scal')->text());
    }

    private function socixWithModuleOn(): KernelBrowser
    {
        $client = $this->createPartnerAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        return $client;
    }

    /**
     * Dos áreas: la primera la marca el socix de las fixtures, la segunda no.
     *
     * @return array{VolunteerCategory, VolunteerCategory}
     */
    private function prepareAreas(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $partner = $em->getRepository(User::class)
            ->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME)
            ->getPartner();

        // El nombre del área es único en BBDD y cada test crea las suyas.
        $suffix = ' '.uniqid();
        $mine = (new VolunteerCategory())->setName('Areas marcada'.$suffix);
        $other = (new VolunteerCategory())->setName('Areas no marcada'.$suffix);
        $em->persist($mine);
        $em->persist($other);
        $partner->addVolunteerCategory($mine);
        $em->flush();

        return [$mine, $other];
    }

    private function makeShift(string $title, VolunteerCategory $category): VolunteerShift
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setSlots(3)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->addCategory($category);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime(self::MONTH.'-12 10:00:00'));
        $offer->addShift($shift);

        $em->persist($offer);
        $em->persist($shift);
        $em->flush();

        return $shift;
    }
}
