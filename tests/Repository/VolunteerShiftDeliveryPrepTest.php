<?php

namespace App\Tests\Repository;

use App\Entity\Node;
use App\Entity\VolunteerCategory;
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
 * importa aquí es lo que NO entra: una tarea de otro punto, de otra semana, o
 * del mismo nodo y el mismo día pero de otra cosa —limpiar el local, sin ir más
 * lejos— convertiría el bloque en una mentira educada, señalando como "quien te
 * monta la cesta" a gente que ese viernes está fregando el suelo.
 *
 * Va contra la BBDD porque todo el comportamiento —la marca de la categoría, el
 * nodo, la ventana de dos días, el estado publicado— es de la consulta.
 * Autocontenido: crea sus propios datos y comprueba pertenencia, no conteos.
 */
class VolunteerShiftDeliveryPrepTest extends KernelTestCase
{
    /**
     * El caso que motiva que la categoría lleve marca propia: dos tareas en el
     * mismo punto y el mismo día, y sólo una es montar las cestas.
     */
    public function testOtraTareaDelMismoNodoYDiaNoCuentaComoMontaje(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $node = $this->makeNode($em, 'Prep Torremocha');

        $montaje = $this->makeOffer($em, 'Prep montar cestas', $node, $entrega, $this->makeCategory($em, 'Prep reparto', prep: true));
        $limpieza = $this->makeOffer($em, 'Prep limpiar el local', $node, $entrega, $this->makeCategory($em, 'Prep local', prep: false));
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
        $categoria = $this->makeCategory($em, 'Prep reparto nodos', prep: true);
        $mio = $this->makeNode($em, 'Prep mi punto');
        $otro = $this->makeNode($em, 'Prep otro punto');

        $aqui = $this->makeOffer($em, 'Prep montaje aquí', $mio, $entrega, $categoria);
        $alli = $this->makeOffer($em, 'Prep montaje allí', $otro, $entrega, $categoria);
        $em->flush();

        $found = $this->repository($em)->findDeliveryPrepFor($mio, $entrega);

        $this->assertContains($aqui, $found);
        $this->assertNotContains($alli, $found);
    }

    /**
     * La ventana llega hasta la víspera —hay puntos donde las cestas se montan la
     * tarde anterior— y no más atrás: el montaje de la semana pasada no es el de
     * esta entrega.
     */
    public function testEntraLaVisperaPeroNoLaSemanaAnterior(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $categoria = $this->makeCategory($em, 'Prep reparto ventana', prep: true);
        $node = $this->makeNode($em, 'Prep punto ventana');

        $vispera = $this->makeOffer($em, 'Prep víspera', $node, $entrega->modify('-1 day'), $categoria);
        $mismoDia = $this->makeOffer($em, 'Prep mismo día', $node, $entrega, $categoria);
        $semanaPasada = $this->makeOffer($em, 'Prep semana pasada', $node, $entrega->modify('-7 days'), $categoria);
        $em->flush();

        $found = $this->repository($em)->findDeliveryPrepFor($node, $entrega);

        $this->assertContains($vispera, $found);
        $this->assertContains($mismoDia, $found);
        $this->assertNotContains($semanaPasada, $found);
    }

    /**
     * Un borrador todavía no se le ha ofrecido a nadie: anunciarlo en la home
     * como el montaje de la semana daría por hecho un plan que aún se está
     * escribiendo.
     */
    public function testUnBorradorNoSeAnuncia(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entrega = new \DateTimeImmutable('+7 days');
        $categoria = $this->makeCategory($em, 'Prep reparto borrador', prep: true);
        $node = $this->makeNode($em, 'Prep punto borrador');

        $borrador = $this->makeOffer($em, 'Prep borrador', $node, $entrega, $categoria);
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

    private function makeCategory(EntityManagerInterface $em, string $name, bool $prep): VolunteerCategory
    {
        $category = (new VolunteerCategory())
            ->setName($name)
            ->setDeliveryPrep($prep);

        $em->persist($category);

        return $category;
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
     * Una tarea publicada en un nodo, con una categoría y UN turno a las seis de
     * la tarde. Devuelve el turno: es lo que devuelve la consulta desde que el
     * momento vive en su propia fila.
     *
     * El estado por defecto de VolunteerOffer es borrador, así que hay que
     * publicarla a mano.
     */
    private function makeOffer(
        EntityManagerInterface $em,
        string $title,
        Node $node,
        \DateTimeInterface $startsAt,
        VolunteerCategory $category,
    ): VolunteerShift {
        $offer = (new VolunteerOffer())
            ->setTitle($title)
            ->setNode($node)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $offer->addCategory($category);

        $shift = (new VolunteerShift())
            ->setStartsAt(\DateTime::createFromInterface($startsAt)->setTime(18, 0));
        $offer->addShift($shift);

        $em->persist($offer);
        $em->persist($shift);

        return $shift;
    }
}
