<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Service\Delivery\AccumulatingMove;
use App\Service\Delivery\ExtraBasketAdder;
use App\Service\Delivery\ExtraBasketRemover;
use App\Service\Delivery\PartnerDeliverySkipper;
use App\Service\Delivery\PartnerMonthProjection;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del traslado SUMANDO y de su vuelta atrás.
 *
 * Lo que se fija aquí:
 *  - lo que lleva el origen se SUMA al destino (cesta extra) y el origen se vacía, en ese
 *    ORDEN — primero suma, luego vacía, para que un fallo intermedio nunca haga desaparecer
 *    una cesta en silencio;
 *  - el intent que vacía el origen se marca con la semana destino (`accumulatedTo`), que es
 *    lo que impide que esa cesta se cuente DOS veces (sumada allí y pendiente en la papelera);
 *  - deshacer va al revés: primero devuelve las cestas a su semana, después quita el añadido.
 */
class AccumulatingMoveTest extends TestCase
{
    private const ID_VEG = 1;
    private const ID_EGG = 2;

    public function testTrasladaLaComposicionDelOrigenAlDestinoYVaciaElOrigen(): void
    {
        $partner = $this->createStub(Partner::class);
        $from = $this->basket(100, '2026-06-05');
        $to = $this->basket(200, '2026-06-19');

        // El origen lleva 1 cesta de verdura + 1 docena de huevos.
        $projector = $this->createMock(PartnerMonthProjection::class);
        $projector->method('projectMonth')->with($partner, 2026, 6)->willReturn([
            ['basket' => $this->basket(99, '2026-06-01'), 'items' => [['component' => $this->component(self::ID_VEG), 'amount' => '5.00']]],
            ['basket' => $from, 'items' => [
                ['component' => $this->component(self::ID_VEG), 'amount' => '1.00'],
                ['component' => $this->component(self::ID_EGG), 'amount' => '1.00'],
            ]],
        ]);

        $calls = [];
        $capturedAmounts = null;
        $capturedBasket = null;

        $extraEditor = $this->createMock(ExtraBasketAdder::class);
        $extraEditor->method('addToDelivery')->willReturnCallback(
            function (Partner $p, Basket $b, array $amounts) use (&$calls, &$capturedAmounts, &$capturedBasket): void {
                $calls[] = 'add';
                $capturedBasket = $b;
                $capturedAmounts = $amounts;
            }
        );

        $applier = $this->createMock(PartnerDeliverySkipper::class);
        $capturedAccumulatedTo = null;
        $applier->method('applySkipIntent')->willReturnCallback(
            function (Partner $p, Basket $b, ?BasketComponent $c, ?string $actor, ?Basket $accumulatedTo) use (&$calls, &$capturedAccumulatedTo): PartnerDeliveryShift {
                $calls[] = 'skip';
                $capturedAccumulatedTo = $accumulatedTo;

                return $this->createStub(PartnerDeliveryShift::class);
            }
        );
        // Origen "de patrón": sin cambio entrante → se vacía con applySkipIntent.
        $shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);
        $shiftRepo->method('findIncoming')->willReturn(null);
        $applier->expects($this->never())->method('skipMovedDelivery');

        $this->service($applier, $extraEditor, $projector, $shiftRepo)->move($partner, $from, $to);

        $this->assertSame(['add', 'skip'], $calls, 'primero suma al destino, después vacía el origen');
        $this->assertSame($to, $capturedBasket, 'la suma va al destino');
        $this->assertSame([self::ID_VEG => '1.00', self::ID_EGG => '1.00'], $capturedAmounts, 'traslada lo que lleva el origen');
        $this->assertSame($to, $capturedAccumulatedTo, 'el intent del origen queda marcado como trasladado al destino, no como "no recoge"');
    }

    public function testOrigenConCambioEntranteSeVaciaReApuntandoElCambioNoConUnSkipNuevo(): void
    {
        // El origen es el DESTINO de un move previo (tiene shift entrante): vaciarlo debe
        // RE-APUNTAR ese cambio (skipMovedDelivery), no crear un skip nuevo (applySkipIntent),
        // que dejaría el día con dos estados a la vez.
        $partner = $this->createStub(Partner::class);
        $from = $this->basket(100, '2026-06-05');
        $to = $this->basket(200, '2026-06-19');

        $projector = $this->createMock(PartnerMonthProjection::class);
        $projector->method('projectMonth')->willReturn([
            ['basket' => $from, 'items' => [['component' => $this->component(self::ID_VEG), 'amount' => '1.00']]],
        ]);

        $extraEditor = $this->createMock(ExtraBasketAdder::class);
        $extraEditor->expects($this->once())->method('addToDelivery');

        $incoming = $this->createStub(PartnerDeliveryShift::class);
        $shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);
        $shiftRepo->method('findIncoming')->with($partner, $from)->willReturn($incoming);

        $applier = $this->createMock(PartnerDeliverySkipper::class);
        // El re-apuntado también lleva la marca del destino: si no, el día de origen volvería
        // a salir en la papelera con la cesta ya colocada en $to.
        $applier->expects($this->once())->method('skipMovedDelivery')->with($incoming, null, $to);
        $applier->expects($this->never())->method('applySkipIntent');

        $this->service($applier, $extraEditor, $projector, $shiftRepo)->move($partner, $from, $to);
    }

    public function testOrigenSinNadaQueTrasladarLanzaExcepcionYNoTocaNada(): void
    {
        $partner = $this->createStub(Partner::class);
        $from = $this->basket(100, '2026-06-05');
        $to = $this->basket(200, '2026-06-19');

        $projector = $this->createMock(PartnerMonthProjection::class);
        $projector->method('projectMonth')->willReturn([
            ['basket' => $from, 'items' => []],
        ]);

        $extraEditor = $this->createMock(ExtraBasketAdder::class);
        $extraEditor->expects($this->never())->method('addToDelivery');
        $applier = $this->createMock(PartnerDeliverySkipper::class);
        $applier->expects($this->never())->method('applySkipIntent');
        $applier->expects($this->never())->method('skipMovedDelivery');
        $shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);

        $this->expectException(\LogicException::class);

        $this->service($applier, $extraEditor, $projector, $shiftRepo)->move($partner, $from, $to);
    }

    public function testDeshacerDevuelveLasCestasASuSemanaYDespuesQuitaElAnadido(): void
    {
        // Orden inverso al de move(): si algo falla en medio, queda una cesta de MÁS
        // (visible en el listado), nunca una de menos.
        $partner = $this->createStub(Partner::class);
        $origin = $this->basket(100, '2026-06-05');
        $to = $this->basket(200, '2026-06-19');

        $shift = $this->createStub(PartnerDeliveryShift::class);
        $shift->method('getFromBasket')->willReturn($origin);

        $shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);
        $shiftRepo->method('findAccumulatedInto')->with($partner, $to)->willReturn([$shift]);

        $calls = [];
        $applier = $this->createMock(PartnerDeliverySkipper::class);
        $applier->method('cancelSkipIntent')->willReturnCallback(
            function () use (&$calls): void { $calls[] = 'cancel'; }
        );

        $capturedRemovedFrom = null;
        $extraRemover = $this->createMock(ExtraBasketRemover::class);
        $extraRemover->method('removeExtra')->willReturnCallback(
            function (Partner $p, Basket $b) use (&$calls, &$capturedRemovedFrom): bool {
                $calls[] = 'removeExtra';
                $capturedRemovedFrom = $b;

                return true;
            }
        );

        $service = $this->service(
            $applier,
            $this->createMock(ExtraBasketAdder::class),
            $this->createMock(PartnerMonthProjection::class),
            $shiftRepo,
            $extraRemover,
        );

        $returned = $service->undo($partner, $to);

        $this->assertSame(1, $returned, 'devuelve cuántas cestas han vuelto a su semana');
        $this->assertSame(['cancel', 'removeExtra'], $calls, 'primero devuelve la cesta, después quita el añadido');
        $this->assertSame($to, $capturedRemovedFrom, 'el añadido se quita del día que recibió las cestas');
    }

    public function testDeshacerSobreUnDiaSinCestasTrasladadasLanzaYNoTocaNada(): void
    {
        $partner = $this->createStub(Partner::class);
        $to = $this->basket(200, '2026-06-19');

        $shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);
        $shiftRepo->method('findAccumulatedInto')->willReturn([]);

        $applier = $this->createMock(PartnerDeliverySkipper::class);
        $applier->expects($this->never())->method('cancelSkipIntent');
        $extraRemover = $this->createMock(ExtraBasketRemover::class);
        // Clave: una cesta extra GENUINA no se toca por este camino.
        $extraRemover->expects($this->never())->method('removeExtra');

        $this->expectException(\LogicException::class);

        $this->service(
            $applier,
            $this->createMock(ExtraBasketAdder::class),
            $this->createMock(PartnerMonthProjection::class),
            $shiftRepo,
            $extraRemover,
        )->undo($partner, $to);
    }

    /**
     * El servicio con sus siete colaboradores. Los tres que casi ningún caso ejercita
     * (remover, generador, EM) se rellenan con dobles inertes: el EM devuelve repositorios
     * que no encuentran nada, de modo que `undo` trata las semanas de origen como NO
     * generadas y no entra a re-materializar piedra (eso es terreno de test de integración).
     */
    private function service(
        PartnerDeliverySkipper $applier,
        ExtraBasketAdder $extraEditor,
        PartnerMonthProjection $projector,
        PartnerDeliveryShiftRepository $shiftRepository,
        ?ExtraBasketRemover $extraRemover = null,
    ): AccumulatingMove {
        $emptyRepo = $this->createStub(ObjectRepository::class);
        $emptyRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($emptyRepo);

        return new AccumulatingMove(
            $applier,
            $extraEditor,
            $extraRemover ?? $this->createMock(ExtraBasketRemover::class),
            $projector,
            $shiftRepository,
            $this->createMock(WeeklyBasketGenerator::class),
            $em,
        );
    }

    private function basket(int $id, string $date): Basket
    {
        $b = $this->createStub(Basket::class);
        $b->method('getId')->willReturn($id);
        $b->method('getDate')->willReturn(new \DateTime($date));

        return $b;
    }

    private function component(int $id): BasketComponent
    {
        $c = $this->createStub(BasketComponent::class);
        $c->method('getId')->willReturn($id);

        return $c;
    }
}
