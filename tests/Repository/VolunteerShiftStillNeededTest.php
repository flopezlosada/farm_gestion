<?php

namespace App\Tests\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\VolunteerShiftRepository;
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
 * publicado, el recorte— es de la consulta. Autocontenido: crea sus propios
 * turnos y comprueba pertenencia, no conteos, para no depender del estado de
 * db_test.
 */
class VolunteerShiftStillNeededTest extends KernelTestCase
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

        $needed = $this->repository($em)->findStillNeededFor(new \DateTime(), null);

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

        $this->assertContains($abierta, $this->repository($em)->findStillNeededFor(new \DateTime(), null));
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

        $this->makeOffer($em, 'StillNeeded tope primera', slots: 5, inDays: 2);
        $this->makeOffer($em, 'StillNeeded tope segunda', slots: 5, inDays: 3);
        $em->flush();

        $repository = $this->repository($em);

        // Contra lo que de verdad hay, no contra dos tareas concretas: el
        // repositorio NO filtra por nodo (sólo ordena por él), así que la consulta
        // ve TODAS las tareas publicadas y futuras de db_test. Fijar aquí que el
        // top-2 global son las dos de este test lo dejaba a merced de que otro
        // fichero creara una tarea más próxima — y ese acoplamiento no es lo que
        // se quiere proteger.
        $todas = $repository->findStillNeededFor(new \DateTime(), null);
        $dos = $repository->findStillNeededFor(new \DateTime(), null, [], 2);

        $this->assertNotContains($llena, $todas, 'Una tarea llena no hace falta, con límite o sin él.');
        $this->assertNotContains($llena, $dos);

        // La regla: el recorte se aplica DESPUÉS de descartar las llenas. Si
        // fuera antes, el cupo se gastaría en tareas que luego se descartan y
        // saldrían menos de las que caben.
        $this->assertCount(min(2, \count($todas)), $dos, 'El límite recorta lo que hace falta, no lo que había antes de filtrar.');
        $this->assertSame(\array_slice($todas, 0, 2), $dos, 'Recortar no puede cambiar el orden.');
    }

    /**
     * Las rutinas no se anuncian entre lo que hace falta: van en su propio
     * montón.
     *
     * Es el ruido que hacía inservible la pantalla. Sacar al perro son dos
     * turnos diarios de una plaza, y en tres semanas sumaban 66 de los 86 turnos
     * futuros: copaban la mitad de la portada y hacían que el aviso dijera "hay
     * 85 turnos esperando gente" cuando el trabajo que de verdad necesitaba a
     * alguien eran veinte.
     */
    public function testUnaRutinaNoSeAnunciaEntreLoQueHaceFalta(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $rutina = $this->makeOffer($em, 'StillNeeded rutina', slots: 1, routine: true);
        $faena = $this->makeOffer($em, 'StillNeeded faena', slots: 4);
        $em->flush();

        $repository = $this->repository($em);

        $this->assertNotContains($rutina, $repository->findStillNeededFor(new \DateTime(), null));
        $this->assertContains($faena, $repository->findStillNeededFor(new \DateTime(), null));

        // Y no desaparecen: siguen estando donde les toca, para quien quiera
        // apuntarse. Sacarlas del listado sin dejarlas en ningún sitio sería
        // esconder trabajo que alguien tiene que hacer igualmente.
        $rutinas = $repository->findRoutineStillNeededFor(new \DateTime(), null);
        $this->assertContains($rutina, $rutinas);
        $this->assertNotContains($faena, $rutinas, 'El montón de rutinas es sólo de rutinas.');
    }

    /**
     * Un turno por tarea, y en el mismo orden: una tarea semanal llenaba ella
     * sola los ocho huecos del montón con ocho sábados seguidos, tapando las
     * otras cinco cosas que también necesitaban gente.
     */
    public function testUnTurnoPorTareaConservandoElOrden(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        // Tres turnos de LA MISMA tarea (el sábado que viene y los dos
        // siguientes) y uno de otra, en medio por fecha.
        $semanal = (new VolunteerOffer())
            ->setTitle('StillNeeded semanal')
            ->setSlots(4)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $primero = (new VolunteerShift())->setStartsAt(new \DateTime('+2 days'));
        $segundo = (new VolunteerShift())->setStartsAt(new \DateTime('+9 days'));
        $tercero = (new VolunteerShift())->setStartsAt(new \DateTime('+16 days'));
        foreach ([$primero, $segundo, $tercero] as $shift) {
            $semanal->addShift($shift);
            $em->persist($shift);
        }
        $em->persist($semanal);

        $otra = $this->makeOffer($em, 'StillNeeded otra tarea', slots: 2, inDays: 5);
        $em->flush();

        $uno = $this->repository($em)->onePerOffer([$primero, $otra, $segundo, $tercero]);

        $this->assertSame([$primero, $otra], $uno, 'Se queda el primero de cada tarea, sin reordenar.');
    }

    private function repository(EntityManagerInterface $em): VolunteerShiftRepository
    {
        /** @var VolunteerShiftRepository $repository */
        $repository = $em->getRepository(VolunteerShift::class);

        return $repository;
    }

    /**
     * Un turno publicado y futuro, que es el único que estas consultas miran. El
     * estado por defecto de VolunteerOffer es borrador, así que hay que
     * publicarla a mano.
     *
     * Devuelve el TURNO y no la tarea: es lo que devuelven las consultas desde
     * que el momento vive en su propia fila.
     */
    private function makeOffer(
        EntityManagerInterface $em,
        string $title,
        ?int $slots,
        int $inDays = 7,
        bool $routine = false,
    ): VolunteerShift {
        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setSlots($slots)
            ->setRoutine($routine)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime(sprintf('+%d days', $inDays)));
        $offer->addShift($shift);

        $em->persist($offer);
        $em->persist($shift);

        return $shift;
    }

    private function makeSignup(
        EntityManagerInterface $em,
        VolunteerShift $shift,
        Partner $partner,
        int $companions,
    ): VolunteerSignup {
        $signup = (new VolunteerSignup())
            ->setShift($shift)
            ->setPartner($partner)
            ->setCompanions($companions);

        $em->persist($signup);
        $shift->getSignups()->add($signup);

        return $signup;
    }

    private function makePartner(EntityManagerInterface $em, string $name): Partner
    {
        $partner = (new Partner())->setName($name);
        $em->persist($partner);

        return $partner;
    }
}
