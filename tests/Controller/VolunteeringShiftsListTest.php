<?php

namespace App\Tests\Controller;

use App\Entity\Partner;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La lista completa de turnos de UNA tarea, y lo que la ficha deja de enseñar.
 *
 * La ficha de la tarea se quedó sólo con lo que viene; lo que ya pasó —cerrado o
 * pendiente de pasar lista— vive en su propia lista con vistas. Esto vigila las
 * dos mitades del reparto: que la lista filtre por tarea y por vista, y que la
 * ficha no vuelva a mezclar el histórico con los próximos.
 *
 * Autocontenido: crea su tarea con un turno pasado sin cerrar y otro por venir, y
 * comprueba por contenido, no por conteos, para no depender del estado de db_test.
 */
class VolunteeringShiftsListTest extends WebTestCase
{
    private const PAST_DATE = '2001-03-15 10:00:00';
    private const FUTURE_DATE = '2099-11-20 18:00:00';

    /**
     * Limpia el toggle para no contaminar a quien cuente con el default.
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
     * La vista «sin confirmar» enseña el turno pasado con gente sin responder y
     * no el que está por venir; la de «por venir», al revés.
     */
    public function testLaListaFiltraPorVista(): void
    {
        $client = $this->clientLoggedAs('admin');
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);
        $offer = $this->makeOfferWithPastAndFuture('Lista por tarea');

        $client->request('GET', sprintf('/gestion/voluntariado/tarea/%d/turnos?ver=pending', $offer->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-table', '15 mar');
        $this->assertSelectorTextNotContains('.csa-table', '20 nov');

        $client->request('GET', sprintf('/gestion/voluntariado/tarea/%d/turnos?ver=upcoming', $offer->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-table', '20 nov');
        $this->assertSelectorTextNotContains('.csa-table', '15 mar');
    }

    /**
     * La lista es de ESTA tarea: los turnos de otra no se cuelan aunque estén
     * en la misma vista.
     */
    public function testLaListaNoMezclaTareas(): void
    {
        $client = $this->clientLoggedAs('admin');
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);
        $mia = $this->makeOfferWithPastAndFuture('Lista mía');
        $this->makeOfferWithPastAndFuture('Lista ajena', futureDate: '2099-12-24 09:00:00');

        $client->request('GET', sprintf('/gestion/voluntariado/tarea/%d/turnos?ver=all', $mia->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-table', '20 nov');
        $this->assertSelectorTextNotContains('.csa-table', '24 dic');
    }

    /**
     * La ficha de la tarea enseña el turno por venir y remite a la lista para
     * el que está sin cerrar, en vez de pintarlo en una tabla propia.
     */
    public function testLaFichaSoloEnsenaLoQueViene(): void
    {
        $client = $this->clientLoggedAs('admin');
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);
        $offer = $this->makeOfferWithPastAndFuture('Ficha sólo próximos');

        $crawler = $client->request('GET', sprintf('/gestion/voluntariado/tarea/%d', $offer->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-table', '20 nov');
        $this->assertSelectorTextNotContains('.csa-table', '15 mar');
        $this->assertSelectorTextContains('.vol-phase', 'sin cerrar');
        $this->assertCount(
            1,
            $crawler->filter(sprintf('a[href="/gestion/voluntariado/tarea/%d/turnos?ver=pending"]', $offer->getId())),
            'El aviso de turnos sin cerrar tiene que llevar a la lista filtrada.'
        );
    }

    private function clientLoggedAs(string $identifier): KernelBrowser
    {
        $client = static::createClient();
        $user = static::getContainer()->get('doctrine')->getRepository(User::class)->loadUserByIdentifier($identifier);

        if (null === $user) {
            throw new \RuntimeException(sprintf('Fixtures sin User "%s".', $identifier));
        }

        $client->loginUser($user);

        return $client;
    }

    /**
     * Una tarea publicada con un turno pasado —con alguien apuntado que no ha
     * dicho si fue, así que está sin cerrar— y otro por venir.
     */
    private function makeOfferWithPastAndFuture(string $title, string $futureDate = self::FUTURE_DATE): VolunteerOffer
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setSlots(3)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $past = (new VolunteerShift())->setStartsAt(new \DateTime(self::PAST_DATE));
        $future = (new VolunteerShift())->setStartsAt(new \DateTime($futureDate));
        $offer->addShift($past);
        $offer->addShift($future);

        $partner = (new Partner())->setName($title.' apuntada');
        $signup = (new VolunteerSignup())->setShift($past)->setPartner($partner);
        $past->getSignups()->add($signup);

        $em->persist($offer);
        $em->persist($past);
        $em->persist($future);
        $em->persist($partner);
        $em->persist($signup);
        $em->flush();

        return $offer;
    }
}
