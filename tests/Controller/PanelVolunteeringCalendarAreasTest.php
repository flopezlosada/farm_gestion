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
 * resalta, lo demás se apaga, y con `?solo=mias` lo demás ni sale.
 *
 * Es SU calendario de voluntariado: quien marcó huerta no tiene por qué leer
 * el reparto de tres nodos por encima de lo que le interesa.
 */
class PanelVolunteeringCalendarAreasTest extends AbstractPartnerAuthenticatedTest
{
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

        $crawler = $client->request('GET', '/panel/voluntariado/calendario');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Areas mía', $crawler->filter('.vol-item--mine')->text());
        $this->assertStringContainsString('Areas ajena', $crawler->filter('.vol-item--dim')->text());
    }

    public function testConSoloMiasElRestoNiSale(): void
    {
        $client = $this->socixWithModuleOn();
        [$mine, $other] = $this->prepareAreas();
        $this->makeShift('Areas mía sola', $mine);
        $this->makeShift('Areas ajena fuera', $other);

        $client->request('GET', '/panel/voluntariado/calendario?solo=mias');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.vol-list', 'Areas mía sola');
        $this->assertSelectorTextNotContains('.vol-list', 'Areas ajena fuera');
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

        $mine = (new VolunteerCategory())->setName('Areas marcada');
        $other = (new VolunteerCategory())->setName('Areas no marcada');
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

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('+7 days 10:00'));
        $offer->addShift($shift);

        $em->persist($offer);
        $em->persist($shift);
        $em->flush();

        return $shift;
    }
}
