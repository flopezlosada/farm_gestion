<?php

namespace App\Tests\Repository;

use App\Entity\Node;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Repository\VolunteerShiftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qué cuenta como «el montaje de MI reparto».
 *
 * De esta consulta sale el bloque de la home que le dice a cada socix quién le
 * está preparando la cesta, y el aviso de que no se ha apuntado nadie. Los dos
 * mensajes son afirmaciones fuertes sobre personas concretas, así que lo que
 * importa aquí es lo que NO entra: un turno de otro punto, de otra semana, o del
 * mismo punto y el mismo día pero de otra cosa —limpiar el local, sin ir más
 * lejos— convertiría el bloque en una mentira educada, señalando como «quien te
 * monta la cesta» a gente que ese viernes está fregando el suelo.
 *
 * LA VENTANA LA DECLARA EL PUNTO. Antes eran dos días fijos para abarcar tanto el
 * montaje del mismo día como el de la víspera, y quien decidía cuál de los dos
 * casos era no lo decía nadie: se cogía lo que hubiera dentro. Ahora el punto
 * dice cuándo monta, así que la ventana es exactamente la que ese punto necesita.
 *
 * Va contra la BBDD porque todo el comportamiento —la marca de la convocatoria,
 * el punto, la ventana, el estado publicado— es de la consulta. Autocontenido:
 * crea sus propios datos y comprueba pertenencia, no conteos.
 */
class VolunteerShiftDeliveryPrepTest extends KernelTestCase
{
    /**
     * Dos tareas en el mismo punto y el mismo día, y sólo una es montar las
     * cestas. Es el caso que motivaba la marca, y el que sigue mandando: ahora la
     * lleva la convocatoria, que es de quien se afirma algo.
     */
    public function testOtraTareaDelMismoNodoYDiaNoCuentaComoMontaje(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $node = $this->makeNode($em, 'Prep Torremocha', offset: 0);

        $montaje = $this->makeShift($em, 'Prep montar cestas', $node, $entrega, prep: true);
        $limpieza = $this->makeShift($em, 'Prep limpiar el local', $node, $entrega, prep: false);
        $em->flush();

        $found = $this->repository($em)->findDeliveryPrepFor($node, $entrega);

        $this->assertContains($montaje, $found);
        $this->assertNotContains($limpieza, $found, 'Limpiar el local no es montar las cestas.');
    }

    /**
     * El montaje de otro punto no dice nada a quien recoge en el suyo: son otras
     * personas y otras cestas.
     */
    public function testElMontajeDeOtroNodoNoEsElMio(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $mio = $this->makeNode($em, 'Prep mi punto', offset: 0);
        $otro = $this->makeNode($em, 'Prep otro punto', offset: 0);

        $aqui = $this->makeShift($em, 'Prep montaje aquí', $mio, $entrega, prep: true);
        $alli = $this->makeShift($em, 'Prep montaje allí', $otro, $entrega, prep: true);
        $em->flush();

        $found = $this->repository($em)->findDeliveryPrepFor($mio, $entrega);

        $this->assertContains($aqui, $found);
        $this->assertNotContains($alli, $found);
    }

    /**
     * En un punto que monta la víspera, la ventana llega hasta el día anterior y
     * no más atrás: el montaje de la semana pasada no es el de esta entrega.
     */
    public function testEnUnPuntoDeVisperaEntraElDiaAnteriorPeroNoLaSemanaAnterior(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $node = $this->makeNode($em, 'Prep punto víspera', offset: -1);

        $vispera = $this->makeShift($em, 'Prep víspera', $node, $entrega->modify('-1 day'), prep: true);
        $mismoDia = $this->makeShift($em, 'Prep mismo día', $node, $entrega, prep: true);
        $semanaPasada = $this->makeShift($em, 'Prep semana pasada', $node, $entrega->modify('-7 days'), prep: true);
        $em->flush();

        $found = $this->repository($em)->findDeliveryPrepFor($node, $entrega);

        $this->assertContains($vispera, $found);
        $this->assertContains($mismoDia, $found);
        $this->assertNotContains($semanaPasada, $found);
    }

    /**
     * Y en un punto que monta el mismo día, la víspera ya NO entra. Es el cambio
     * que trae declarar la ventana: antes se colaba porque los dos días fijos
     * tenían que servir para los dos casos a la vez.
     */
    public function testEnUnPuntoDelMismoDiaLaVisperaNoEntra(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $node = $this->makeNode($em, 'Prep punto mismo día', offset: 0);

        $vispera = $this->makeShift($em, 'Prep víspera ajena', $node, $entrega->modify('-1 day'), prep: true);
        $mismoDia = $this->makeShift($em, 'Prep el día', $node, $entrega, prep: true);
        $em->flush();

        $found = $this->repository($em)->findDeliveryPrepFor($node, $entrega);

        $this->assertContains($mismoDia, $found);
        $this->assertNotContains($vispera, $found, 'Este punto monta el mismo día: lo de la víspera es otra cosa.');
    }

    /**
     * Un punto que no monta con voluntariado no tiene nada que contar, aunque
     * tenga turnos suyos ese día. Sin esto, apagar el montaje dejaría la tarjeta
     * señalando a quien siguiera apuntado a la convocatoria en pausa.
     */
    public function testUnPuntoQueNoMontaConVoluntariadoNoDevuelveNada(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $node = $this->makeNode($em, 'Prep punto sin montaje', offset: 0, prep: false);

        $this->makeShift($em, 'Prep huérfano', $node, $entrega, prep: true);
        $em->flush();

        $this->assertSame([], $this->repository($em)->findDeliveryPrepFor($node, $entrega));
    }

    /**
     * Un borrador todavía no se le ha ofrecido a nadie: anunciarlo en la home
     * como el montaje de la semana daría por hecho un plan que aún se está
     * escribiendo. Importa más que antes, porque la convocatoria de montaje NACE
     * en borrador.
     */
    public function testUnBorradorNoSeAnuncia(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $node = $this->makeNode($em, 'Prep punto borrador', offset: 0);

        $borrador = $this->makeShift($em, 'Prep borrador', $node, $entrega, prep: true);
        $borrador->getOffer()->setStatus(VolunteerOffer::STATUS_DRAFT);
        $em->flush();

        $this->assertNotContains($borrador, $this->repository($em)->findDeliveryPrepFor($node, $entrega));
    }

    private function repository(EntityManagerInterface $em): VolunteerShiftRepository
    {
        /** @var VolunteerShiftRepository $repository */
        $repository = $em->getRepository(VolunteerShift::class);

        return $repository;
    }

    /**
     * Un punto de recogida que monta sus cestas con voluntariado a las seis de la
     * tarde, con el desfase que pida el caso.
     *
     * @param int  $offset 0 monta el mismo día, -1 la víspera
     * @param bool $prep   si el punto monta con voluntariado
     */
    private function makeNode(EntityManagerInterface $em, string $name, int $offset, bool $prep = true): Node
    {
        $node = (new Node())
            ->setName($name)
            ->setDeliveryWeekday(5)
            ->setDeliveryPrep($prep)
            ->setDeliveryPrepDayOffset($offset)
            ->setDeliveryPrepTime(new \DateTimeImmutable('18:00'));

        $em->persist($node);

        return $node;
    }

    /**
     * Una tarea publicada en un punto, con UN turno a las seis de la tarde.
     * Devuelve el turno: es lo que devuelve la consulta desde que el momento vive
     * en su propia fila.
     *
     * El estado por defecto de VolunteerOffer es borrador, así que hay que
     * publicarla a mano.
     *
     * @param bool $prep si la tarea es el montaje de las cestas de ese punto
     */
    private function makeShift(
        EntityManagerInterface $em,
        string $title,
        Node $node,
        \DateTimeInterface $startsAt,
        bool $prep,
    ): VolunteerShift {
        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setNode($node)
            ->setDeliveryPrep($prep)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $shift = (new VolunteerShift())
            ->setStartsAt(\DateTime::createFromInterface($startsAt)->setTime(18, 0));
        $offer->addShift($shift);

        $em->persist($offer);
        $em->persist($shift);

        return $shift;
    }
}
