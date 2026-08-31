<?php

namespace App\Tests\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use App\Repository\VolunteerEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qué actividad ve cada quien.
 *
 * Esto NO es un test de comodidad: el filtro por área es la frontera entre "ver
 * lo mío" y "ver lo de toda la asociación", y vive entero en la consulta. Quien
 * coordina el reparto no tiene ningún rol global; si el WHERE se rompiera, la
 * pantalla seguiría cargando y le enseñaría de más sin que nadie se enterara.
 *
 * Va contra la BBDD por lo mismo que PartnerVolunteeringAudienceTest: la regla
 * está en el SQL y con dobles no se ejercitaría.
 *
 * Autocontenido: crea sus propios datos y comprueba pertenencia, no conteos, así
 * que no depende de lo que ya haya en db_test.
 */
class VolunteerEventScopeTest extends KernelTestCase
{
    /**
     * Quien coordina un área ve los eventos de las tareas de esa área, y no ve
     * los de las tareas de otra.
     *
     * Es el caso central de la pantalla de actividad.
     */
    public function testCoordinarUnAreaNoDejaVerLaActividadDeOtra(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mia = $this->makeCategory($em, 'VolScope Mía');
        $ajena = $this->makeCategory($em, 'VolScope Ajena');

        $eventoMio = $this->makeEvent($em, offer: $this->makeOffer($em, 'VolScope Tarea mía', $mia));
        $eventoAjeno = $this->makeEvent($em, offer: $this->makeOffer($em, 'VolScope Tarea ajena', $ajena));
        $em->flush();

        $visto = $this->feed($em, [$mia]);

        $this->assertContains($eventoMio, $visto);
        $this->assertNotContains($eventoAjeno, $visto);
    }

    /**
     * Los eventos que no cuelgan de una tarea sino del área en sí —crear un tipo
     * de trabajo, cambiar quién lo coordina— se filtran igual.
     *
     * Sin esto, quien coordina el reparto vería los cambios de coordinación de
     * todas las demás áreas, que es exactamente lo que se pidió que no pasara.
     */
    public function testLosEventosDelAreaSinTareaTambienSeFiltran(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mia = $this->makeCategory($em, 'VolScope Mía2');
        $ajena = $this->makeCategory($em, 'VolScope Ajena2');

        $eventoMio = $this->makeEvent($em, category: $mia, type: VolunteerEvent::TYPE_COORDINATORS_CHANGED);
        $eventoAjeno = $this->makeEvent($em, category: $ajena, type: VolunteerEvent::TYPE_COORDINATORS_CHANGED);
        $em->flush();

        $visto = $this->feed($em, [$mia]);

        $this->assertContains($eventoMio, $visto);
        $this->assertNotContains($eventoAjeno, $visto);
    }

    /**
     * Los eventos sin área ninguna —un socix cambiando sus preferencias— sólo
     * los ve administración.
     *
     * No son de ningún área y no hay forma honesta de repartirlos: si se
     * enseñaran a quien coordina, cada coordinadora vería las preferencias de
     * toda la asociación.
     */
    public function testLosEventosSinAreaNoLosVeQuienSoloCoordina(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mia = $this->makeCategory($em, 'VolScope Mía3');
        $huerfano = $this->makeEvent($em, type: VolunteerEvent::TYPE_PREFERENCES_CHANGED);
        $em->flush();

        $this->assertNotContains($huerfano, $this->feed($em, [$mia]));
        $this->assertContains($huerfano, $this->feed($em, null));
    }

    /**
     * No coordinar nada no es lo mismo que verlo todo.
     *
     * El distingo está en el tipo: null significa "ve todo" y la lista vacía
     * significa "no coordina nada". Confundirlos abriría la actividad entera a
     * cualquier cuenta de gestión, que es el fallo caro de esta pantalla.
     */
    public function testNoCoordinarNadaNoEsVerloTodo(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $evento = $this->makeEvent($em, offer: $this->makeOffer($em, 'VolScope Tarea suelta', $this->makeCategory($em, 'VolScope Mía4')));
        $em->flush();

        $this->assertSame([], $this->feed($em, []));
        $this->assertContains($evento, $this->feed($em, null));
    }

    /**
     * El historial de una persona es esta misma consulta filtrada por ella, y el
     * filtro por área sigue encima: de esa persona se ve lo del área propia.
     */
    public function testElFiltroPorSocixNoSaltaElFiltroPorArea(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mia = $this->makeCategory($em, 'VolScope Mía5');
        $ajena = $this->makeCategory($em, 'VolScope Ajena5');

        $quien = (new Partner())->setName('VolScope Persona');
        $em->persist($quien);
        $otra = (new Partner())->setName('VolScope Otra');
        $em->persist($otra);

        $suyoEnMiArea = $this->makeEvent($em, offer: $this->makeOffer($em, 'VolScope T1', $mia), partner: $quien);
        $suyoEnOtraArea = $this->makeEvent($em, offer: $this->makeOffer($em, 'VolScope T2', $ajena), partner: $quien);
        $deOtraPersona = $this->makeEvent($em, offer: $this->makeOffer($em, 'VolScope T3', $mia), partner: $otra);
        $em->flush();

        $visto = $this->feed($em, [$mia], $quien);

        $this->assertContains($suyoEnMiArea, $visto);
        $this->assertNotContains($suyoEnOtraArea, $visto);
        $this->assertNotContains($deOtraPersona, $visto);
    }

    /**
     * Lanza la consulta de actividad.
     *
     * @param list<VolunteerCategory>|null $restrictTo áreas de quien mira
     * @param Partner|null                 $partner    socix por el que filtrar
     *
     * @return list<VolunteerEvent> lo que vería en pantalla
     */
    private function feed(EntityManagerInterface $em, ?array $restrictTo, ?Partner $partner = null): array
    {
        /** @var VolunteerEventRepository $repository */
        $repository = $em->getRepository(VolunteerEvent::class);

        return $repository->feedQb($restrictTo, null, $partner)->getQuery()->getResult();
    }

    private function makeCategory(EntityManagerInterface $em, string $name): VolunteerCategory
    {
        $category = (new VolunteerCategory())->setName($name);
        $em->persist($category);

        return $category;
    }

    private function makeOffer(EntityManagerInterface $em, string $title, VolunteerCategory $category): VolunteerOffer
    {
        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setStartsAt(new \DateTime('+1 day'))
            ->addCategory($category);
        $em->persist($offer);

        return $offer;
    }

    private function makeEvent(
        EntityManagerInterface $em,
        ?VolunteerOffer $offer = null,
        ?VolunteerCategory $category = null,
        ?Partner $partner = null,
        string $type = VolunteerEvent::TYPE_OFFER_CREATED,
    ): VolunteerEvent {
        $event = (new VolunteerEvent())
            ->setType($type)
            ->setOffer($offer)
            ->setCategory($category)
            ->setPartner($partner)
            ->setActor(VolunteerEvent::ACTOR_SYSTEM);
        $em->persist($event);

        return $event;
    }
}
