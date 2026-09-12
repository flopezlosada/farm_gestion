<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerNodeOverride;
use App\Entity\WeeklyBasketGroup;
use App\Service\Delivery\SharedPairDeliveryEditor;
use App\Service\Delivery\SharedPairException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La garantía que sostiene toda la feature de cestas compartidas: las dos mitades se
 * mueven JUNTAS, o no se mueve ninguna.
 *
 * Es lo que exige la ley L5 ({@see \App\Service\Delivery\Invariant\SharedPairsTogetherInvariant}):
 * una cesta compartida es una cesta física que dos familias parten, así que media cesta
 * un viernes y la otra media el siguiente no es un reparto, es un problema en el punto de
 * recogida. Sin este test, quitar el `wrapInTransaction` del editor no pondría nada en
 * rojo.
 *
 * Los socixs van sin punto de recogida a propósito: sin nodo, la fecha física es la del
 * propio ciclo ({@see \App\Service\Delivery\WeeklyBasketGenerator}), y el escenario deja
 * de depender de la cadencia de ningún punto. Fechas de 2099 para que el plazo esté
 * siempre abierto.
 */
class SharedPairMoveTest extends KernelTestCase
{
    /** Mensual compartida: su patrón ocupa una entrega al mes, así que deja libre el destino. */
    private const SHARE_MONTHLY_SHARED = 7;

    private EntityManagerInterface $em;
    private SharedPairDeliveryEditor $editor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->editor = static::getContainer()->get(SharedPairDeliveryEditor::class);
    }

    /**
     * Pareja compartida con cesta vigente: dos hogares enlazados mutuamente, cada uno con
     * su propia suscripción a la misma modalidad (lo que exige la ley L22).
     *
     * @return array{0: Partner, 1: Partner} Los dos hogares.
     */
    private function pair(): array
    {
        $modality = $this->em->getRepository(BasketShare::class)->find(self::SHARE_MONTHLY_SHARED);
        $this->assertNotNull($modality, 'Catálogo de db_test incompleto: falta la mensual compartida.');

        $households = [];
        foreach (['MilaTest', 'GladysTest'] as $name) {
            $partner = (new Partner())->setName($name . ' ' . uniqid('', true));
            $partner->setIsActive(true);
            $this->em->persist($partner);

            // Sin encadenar: los setters heredados de esta entidad devuelven void.
            $share = new PartnerBasketShare();
            $share->setPartner($partner);
            $share->setBasketShare($modality);
            $share->setStartDate(new \DateTime('2099-01-01'));
            $share->setIsActive(true);
            $share->setDayMonthOrder(1);
            $this->em->persist($share);

            $households[] = $partner;
        }

        $households[0]->setSharePartner($households[1]);
        $households[1]->setSharePartner($households[0]);
        $this->em->flush();

        return $households;
    }

    /**
     * Una semana de reparto.
     *
     * @param string $date Viernes del ciclo.
     * @param int    $week Número de semana.
     */
    private function basket(string $date, int $week): Basket
    {
        $basket = (new Basket())->setDate(new \DateTime($date))->setWeek($week)->setAmount(1);
        $this->em->persist($basket);
        $this->em->flush();

        return $basket;
    }

    /**
     * Los cambios puntuales que tiene un hogar saliendo de una semana.
     *
     * @return PartnerDeliveryShift[]
     */
    private function shiftsFrom(Partner $partner, Basket $from): array
    {
        return $this->em->getRepository(PartnerDeliveryShift::class)
            ->findBy(['partner' => $partner, 'fromBasket' => $from]);
    }

    public function testMoverLaCestaCompartidaMueveLasDosMitades(): void
    {
        [$mila, $gladys] = $this->pair();
        $from = $this->basket('2099-08-14', 33);
        $to = $this->basket('2099-08-21', 34);

        $other = $this->editor->move($mila, $from, $to, 'test');

        $this->assertSame($gladys->getId(), $other->getId(), 'El move devuelve el otro hogar, para poder avisarle.');

        foreach ([$mila, $gladys] as $household) {
            $shifts = $this->shiftsFrom($household, $from);
            $this->assertCount(1, $shifts, 'Cada hogar debe tener su cambio del día viejo al nuevo.');
            $this->assertSame(
                $to->getId(),
                $shifts[0]->getToBasket()?->getId(),
                'Las dos mitades tienen que acabar el MISMO día: es la ley L5.',
            );
        }
    }

    /**
     * Si la segunda mitad no puede, la primera no se queda movida.
     *
     * El bloqueo se provoca con un traslado de punto en esa misma semana, que el motor
     * considera incompatible con un cambio de día ({@see \App\Service\Delivery\DeliveryShiftApplier::move}).
     * Se comprueba de paso que el motivo llega traducido y no como un error crudo: es lo
     * que separa un mensaje en pantalla de un 500.
     */
    public function testSiUnaMitadNoPuedeNoSeMueveLaOtra(): void
    {
        [$mila, $gladys] = $this->pair();
        $from = $this->basket('2099-09-11', 37);
        $to = $this->basket('2099-09-18', 38);

        $group = (new WeeklyBasketGroup())->setName('PuntoTest ' . uniqid('', true));
        $this->em->persist($group);
        $this->em->persist(new PartnerNodeOverride($gladys, $from, $group));
        $this->em->flush();

        try {
            $this->editor->move($mila, $from, $to, 'test');
            $this->fail('Con una mitad bloqueada, el movimiento no debe salir adelante.');
        } catch (SharedPairException $e) {
            $this->assertNotEmpty($e->getMessage(), 'El motivo tiene que poder leerse en pantalla.');
        }

        $this->assertSame([], $this->shiftsFrom($mila, $from), 'La mitad que sí podía no puede quedarse movida sola.');
    }
}
