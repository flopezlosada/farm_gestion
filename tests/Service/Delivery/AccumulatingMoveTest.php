<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Service\Delivery\AccumulatingMove;
use App\Service\Delivery\ExtraBasketAdder;
use App\Service\Delivery\PartnerDeliverySkipper;
use App\Service\Delivery\PartnerMonthProjection;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del traslado SUMANDO: verifica que lo que lleva el origen se suma al destino
 * (cesta extra) y que el origen se vacía ("no recoge"), en ese ORDEN — primero suma, luego
 * vacía, para que un fallo intermedio nunca haga desaparecer una cesta en silencio.
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
        $applier->method('applySkipIntent')->willReturnCallback(
            function () use (&$calls): PartnerDeliveryShift {
                $calls[] = 'skip';

                return $this->createStub(PartnerDeliveryShift::class);
            }
        );
        // Origen "de patrón": sin cambio entrante → se vacía con applySkipIntent.
        $shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);
        $shiftRepo->method('findIncoming')->willReturn(null);
        $applier->expects($this->never())->method('skipMovedDelivery');

        (new AccumulatingMove($applier, $extraEditor, $projector, $shiftRepo))->move($partner, $from, $to);

        $this->assertSame(['add', 'skip'], $calls, 'primero suma al destino, después vacía el origen');
        $this->assertSame($to, $capturedBasket, 'la suma va al destino');
        $this->assertSame([self::ID_VEG => '1.00', self::ID_EGG => '1.00'], $capturedAmounts, 'traslada lo que lleva el origen');
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
        $applier->expects($this->once())->method('skipMovedDelivery')->with($incoming);
        $applier->expects($this->never())->method('applySkipIntent');

        (new AccumulatingMove($applier, $extraEditor, $projector, $shiftRepo))->move($partner, $from, $to);
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

        (new AccumulatingMove($applier, $extraEditor, $projector, $shiftRepo))->move($partner, $from, $to);
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
