<?php

namespace App\Tests\Repository;

use App\Entity\Node;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Repository\VolunteerShiftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El orden con el que se le ofrecen las tareas a un socix.
 *
 * Son tres criterios encadenados —lo destacado, lo de su punto de recogida, la
 * fecha— y el valor está en que se respeten EN ESE ORDEN. Los dos primeros
 * tiran en direcciones distintas a propósito: destacar tiene que poder ganarle
 * al orden automático o no sirve para nada, y el punto propio tiene que seguir
 * mandando cuando nadie ha destacado nada, que es lo normal.
 *
 * Posiciones RELATIVAS y no absolutas: la consulta devuelve todas las tareas
 * futuras de db_test, incluidas las de las fixtures, así que lo que se comprueba
 * es cuál va antes que cuál entre las que crea el propio test.
 */
class VolunteerShiftOrderTest extends KernelTestCase
{
    /**
     * Lo destacado adelanta incluso a lo del punto de recogida propio, que es el
     * criterio más fuerte de los automáticos. Si no lo adelantara, destacar sería
     * una casilla sin efecto.
     */
    public function testLoDestacadoAdelantaALoDelPuntoPropio(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mio = $this->makeNode($em, 'Orden mi punto');
        $otro = $this->makeNode($em, 'Orden otro punto');

        $enMiPunto = $this->makeOffer($em, 'Orden en mi punto', $mio, inDays: 5, featured: false);
        $destacada = $this->makeOffer($em, 'Orden destacada lejos', $otro, inDays: 6, featured: true);
        $em->flush();

        $shifts = $this->repository($em)->findUpcomingForNode(new \DateTime(), $mio);

        $this->assertLessThan(
            $this->positionOf($shifts, $enMiPunto),
            $this->positionOf($shifts, $destacada),
            'Una tarea destacada va por delante aunque sea de otro punto y más tardía.'
        );
    }

    /**
     * Y sin ninguna destacada, el orden de siempre intacto: primero el punto
     * propio aunque sea más tarde. Es la mitad que hace que destacar sea
     * DESTACAR y no FILTRAR — el día que nadie marque nada, la portada sigue
     * enseñando lo que enseñaba.
     */
    public function testSinDestacadasSigueMandandoElPuntoPropio(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mio = $this->makeNode($em, 'Orden normal mi punto');
        $otro = $this->makeNode($em, 'Orden normal otro punto');

        $lejosYPronto = $this->makeOffer($em, 'Orden normal lejos', $otro, inDays: 5, featured: false);
        $cercaYTarde = $this->makeOffer($em, 'Orden normal cerca', $mio, inDays: 6, featured: false);
        $em->flush();

        $shifts = $this->repository($em)->findUpcomingForNode(new \DateTime(), $mio);

        $this->assertLessThan(
            $this->positionOf($shifts, $lejosYPronto),
            $this->positionOf($shifts, $cercaYTarde),
            'Sin nada destacado, la de su punto de recogida sigue yendo primero.'
        );
    }

    /**
     * Entre dos destacadas vuelve a mandar el punto propio: los criterios se
     * encadenan, no se sustituyen.
     */
    public function testEntreDestacadasVuelveAMandarElPuntoPropio(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $mio = $this->makeNode($em, 'Orden dos mi punto');
        $otro = $this->makeNode($em, 'Orden dos otro punto');

        $destacadaLejos = $this->makeOffer($em, 'Orden dos lejos', $otro, inDays: 5, featured: true);
        $destacadaCerca = $this->makeOffer($em, 'Orden dos cerca', $mio, inDays: 6, featured: true);
        $em->flush();

        $shifts = $this->repository($em)->findUpcomingForNode(new \DateTime(), $mio);

        $this->assertLessThan(
            $this->positionOf($shifts, $destacadaLejos),
            $this->positionOf($shifts, $destacadaCerca),
        );
    }

    /**
     * Sin punto de recogida —quien no tiene nodo asignado— lo destacado sigue
     * subiendo. Antes el orden en PHP sólo se aplicaba si había nodo, así que
     * esta persona se quedaba con el orden crudo de la consulta.
     */
    public function testSinPuntoDeRecogidaLoDestacadoSigueSubiendo(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $node = $this->makeNode($em, 'Orden sin nodo punto');

        $pronto = $this->makeOffer($em, 'Orden sin nodo pronto', $node, inDays: 5, featured: false);
        $destacadaTarde = $this->makeOffer($em, 'Orden sin nodo destacada', $node, inDays: 6, featured: true);
        $em->flush();

        $shifts = $this->repository($em)->findUpcomingForNode(new \DateTime(), null);

        $this->assertLessThan(
            $this->positionOf($shifts, $pronto),
            $this->positionOf($shifts, $destacadaTarde),
        );
    }

    /**
     * Dónde ha caído una oferta en el resultado.
     *
     * @param list<VolunteerShift> $shifts el resultado de la consulta
     * @param VolunteerShift       $shift  el que se busca
     *
     * @return int su posición
     */
    private function positionOf(array $shifts, VolunteerShift $shift): int
    {
        $position = array_search($shift, $shifts, true);
        $this->assertNotFalse($position, sprintf(
            '«%s» no aparece en el resultado.',
            $shift->getOffer()?->getTitle() ?? '?'
        ));

        return (int) $position;
    }

    private function repository(EntityManagerInterface $em): VolunteerShiftRepository
    {
        /** @var VolunteerShiftRepository $repository */
        $repository = $em->getRepository(VolunteerShift::class);

        return $repository;
    }

    private function makeNode(EntityManagerInterface $em, string $name): Node
    {
        $node = (new Node())
            ->setName($name)
            ->setDeliveryWeekday(5);

        $em->persist($node);

        return $node;
    }

    /**
     * Una tarea publicada con UN turno futuro, y se devuelve el turno: es lo que
     * ordenan estas consultas desde que el momento vive en su propia fila.
     */
    private function makeOffer(
        EntityManagerInterface $em,
        string $title,
        Node $node,
        int $inDays,
        bool $featured,
    ): VolunteerShift {
        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setNode($node)
            ->setFeatured($featured)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime(sprintf('+%d days', $inDays)));
        $offer->addShift($shift);

        $em->persist($offer);
        $em->persist($shift);

        return $shift;
    }
}
