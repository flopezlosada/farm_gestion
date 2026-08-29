<?php

namespace App\Tests\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Repository\VolunteerOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qué se anuncia como «hace falta una mano».
 *
 * Existe por un fallo real visto en pantalla: la home del panel llamaba a
 * findUpcomingForNode() a pelo mientras el panel de voluntariado filtraba por su
 * cuenta, y acabó ofreciendo una tarea con las dos plazas cubiertas —el texto
 * decía literalmente "faltan 0 personas"— y otra a la que quien miraba ya se
 * había apuntado. El filtro vive ahora en el repositorio, y esto vigila que siga
 * ahí: es la clase de regla que se pierde en cuanto aparece la tercera pantalla
 * que lista tareas.
 *
 * Va contra la BBDD porque la mitad del comportamiento —el orden, el estado
 * publicado, el recorte— es de la consulta. Autocontenido: crea sus propias
 * tareas y comprueba pertenencia, no conteos, para no depender del estado de
 * db_test.
 */
class VolunteerOfferStillNeededTest extends KernelTestCase
{
    /**
     * Una tarea con todas las plazas cubiertas no se anuncia.
     *
     * Es el caso que se vio en producción de la rama: dos plazas, una persona
     * apuntada que se traía un acompañante, y la tarjeta invitando a apuntarse a
     * algo que ya estaba lleno.
     */
    public function testUnaTareaLlenaNoSeAnuncia(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $llena = $this->makeOffer($em, 'StillNeeded llena', slots: 2);
        $libre = $this->makeOffer($em, 'StillNeeded libre', slots: 2);

        // Una persona con un acompañante ocupa las dos plazas de la primera.
        $this->makeSignup($em, $llena, $this->makePartner($em, 'StillNeeded Apuntada'), companions: 1);
        $em->flush();

        $needed = $this->repository($em)->findStillNeededFor(new \DateTime());

        $this->assertNotContains($llena, $needed);
        $this->assertContains($libre, $needed);
    }

    /**
     * Y tampoco se anuncia aquello a lo que quien mira ya se ha apuntado: para
     * eso está su propio bloque, con el botón de darse de baja. Ofrecérselo otra
     * vez como "hace falta" es pedirle algo que ya ha dado.
     */
    public function testLoQueYaTengoApuntadoNoSeMeVuelveAOfrecer(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mia = $this->makeOffer($em, 'StillNeeded mía', slots: 5);
        $otra = $this->makeOffer($em, 'StillNeeded otra', slots: 5);
        $em->flush();

        $needed = $this->repository($em)->findStillNeededFor(new \DateTime(), null, [$mia->getId()]);

        $this->assertNotContains($mia, $needed);
        $this->assertContains($otra, $needed);
    }

    /**
     * Una tarea sin tope de plazas siempre admite gente: no tiene forma de
     * llenarse y no debe desaparecer del listado.
     */
    public function testSinTopeDePlazasSiempreSeAnuncia(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $abierta = $this->makeOffer($em, 'StillNeeded sin tope', slots: null);
        $this->makeSignup($em, $abierta, $this->makePartner($em, 'StillNeeded Voluntaria'), companions: 3);
        $em->flush();

        $this->assertContains($abierta, $this->repository($em)->findStillNeededFor(new \DateTime()));
    }

    /**
     * El límite se aplica DESPUÉS de filtrar, y ésta es la otra mitad del fallo:
     * pedir tres a la consulta y descartar dos por llenas dejaba la home
     * enseñando una sola tarea habiendo más disponibles.
     */
    public function testElLimiteCuentaSoloLoQueDeVerdadHaceFalta(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        // La llena es la más próxima, así que encabeza el orden por fecha y sería
        // la primera en gastar cupo si el recorte fuese antes del filtro.
        $llena = $this->makeOffer($em, 'StillNeeded tope llena', slots: 1, inDays: 1);
        $this->makeSignup($em, $llena, $this->makePartner($em, 'StillNeeded Ocupante'), companions: 0);

        $primera = $this->makeOffer($em, 'StillNeeded tope primera', slots: 5, inDays: 2);
        $segunda = $this->makeOffer($em, 'StillNeeded tope segunda', slots: 5, inDays: 3);
        $em->flush();

        $needed = $this->repository($em)->findStillNeededFor(new \DateTime(), null, [], 2);

        $this->assertCount(2, $needed);
        $this->assertNotContains($llena, $needed);
        $this->assertContains($primera, $needed);
        $this->assertContains($segunda, $needed);
    }

    private function repository(EntityManagerInterface $em): VolunteerOfferRepository
    {
        /** @var VolunteerOfferRepository $repository */
        $repository = $em->getRepository(VolunteerOffer::class);

        return $repository;
    }

    /**
     * Una tarea publicada y futura, que es la única que estas consultas miran.
     * El estado por defecto de VolunteerOffer es borrador, así que hay que
     * publicarla a mano.
     */
    private function makeOffer(EntityManagerInterface $em, string $title, ?int $slots, int $inDays = 7): VolunteerOffer
    {
        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setStartsAt(new \DateTime(sprintf('+%d days', $inDays)))
            ->setSlots($slots)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $em->persist($offer);

        return $offer;
    }

    private function makeSignup(
        EntityManagerInterface $em,
        VolunteerOffer $offer,
        Partner $partner,
        int $companions,
    ): VolunteerSignup {
        $signup = (new VolunteerSignup())
            ->setOffer($offer)
            ->setPartner($partner)
            ->setCompanions($companions);

        $em->persist($signup);
        $offer->getSignups()->add($signup);

        return $signup;
    }

    private function makePartner(EntityManagerInterface $em, string $name): Partner
    {
        $partner = (new Partner())->setName($name);
        $em->persist($partner);

        return $partner;
    }
}
