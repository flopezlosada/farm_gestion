<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Service\Delivery\SharedPairDeliveryEditor;
use App\Service\Delivery\SharedPairException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unit de a quién considera pareja el editor de cestas compartidas, y de lo que rechaza
 * antes de mover nada.
 *
 * Va contra el servicio REAL del contenedor y no contra dobles porque las piezas de las
 * que tira —el applier, el proyector, el relocator— son `final` y no se pueden mockear;
 * es el mismo camino que toma {@see SharedEggMoveTest}. Los socixs se persisten para que
 * tengan id de verdad: sin él, comparar dos `null` haría pasar por buena justo la pareja
 * rota que este test existe para cazar.
 *
 * Que las dos mitades acaben el mismo día lo vigila la ley L5 en la batería de
 * invariantes; aquí lo que importa es que nunca se llegue a mover una sola.
 */
class SharedPairDeliveryEditorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SharedPairDeliveryEditor $editor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->editor = static::getContainer()->get(SharedPairDeliveryEditor::class);
    }

    /**
     * Un socix persistido, para que tenga id.
     *
     * @param string $name Nombre, único por ejecución.
     *
     * @return Partner El socix, ya con id.
     */
    private function partner(string $name): Partner
    {
        $partner = (new Partner())->setName($name . ' ' . uniqid('', true));
        $partner->setIsActive(true);
        $this->em->persist($partner);
        $this->em->flush();

        return $partner;
    }

    /**
     * Dos hogares enlazados el uno con el otro, como los de una compartida real.
     *
     * @return array{0: Partner, 1: Partner} Los dos hogares.
     */
    private function pair(): array
    {
        $one = $this->partner('MilaTest');
        $other = $this->partner('GladysTest');
        $one->setSharePartner($other);
        $other->setSharePartner($one);
        $this->em->flush();

        return [$one, $other];
    }

    public function testUnaCestaQueNoEsCompartidaNoTienePareja(): void
    {
        $this->expectException(SharedPairException::class);

        $this->editor->counterpart($this->partner('SolitariaTest'));
    }

    /**
     * `share_partner_id` que apunta a quien no apunta de vuelta. En el padrón real ha
     * pasado —la pareja de Hilde se invirtió a mano en mayo—, y coordinar contra ese dato
     * movería la cesta de alguien que ya no la comparte.
     */
    public function testUnEnlaceQueNoEsMutuoNoEsPareja(): void
    {
        $one = $this->partner('DesparejadaTest');
        $one->setSharePartner($this->partner('AjenaTest'));
        $this->em->flush();

        $this->expectException(SharedPairException::class);

        $this->editor->counterpart($one);
    }

    public function testUnHogarDeBajaNoEsPareja(): void
    {
        [$one, $other] = $this->pair();
        $other->setIsActive(false);
        $this->em->flush();

        $this->expectException(SharedPairException::class);

        $this->editor->counterpart($one);
    }

    public function testLaParejaSeResuelveCuandoElEnlaceEsMutuoYSigueActiva(): void
    {
        [$one, $other] = $this->pair();

        $this->assertSame($other->getId(), $this->editor->counterpart($one)->getId());
    }

    public function testSinFechaDestinoNoSeMueveNada(): void
    {
        [$one] = $this->pair();

        $this->expectException(SharedPairException::class);

        $this->editor->move($one, $this->basket('2099-08-07', 32), null, 'partner:test');
    }

    /** Mover al día en el que ya está no es un cambio, y no debe colarse como uno. */
    public function testMoverAlMismoDiaNoEsUnCambio(): void
    {
        [$one] = $this->pair();
        $mismo = $this->basket('2099-08-21', 34);

        $this->expectException(SharedPairException::class);

        $this->editor->move($one, $mismo, $mismo, 'partner:test');
    }

    /**
     * Una semana de reparto persistida, para que tenga id.
     *
     * @param string $date Fecha del ciclo.
     * @param int    $week Número de semana.
     *
     * @return Basket La semana.
     */
    private function basket(string $date, int $week): Basket
    {
        $basket = (new Basket())->setDate(new \DateTime($date))->setWeek($week)->setAmount(1);
        $this->em->persist($basket);
        $this->em->flush();

        return $basket;
    }
}
