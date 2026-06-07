<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\DeliveryException;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Repository\BasketRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\ClosureShiftReconciler;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\ShiftReconciliationActions;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de ClosureShiftReconciler con dependencias mockeadas. Verifica las
 * reglas de reconciliación de cambios puntuales al cerrar (cancelar) una semana:
 * salientes (mover/no-recoge) y entrantes (re-apuntar), incluido el guard de
 * doble entrega cuando el destino ya está repartido.
 */
class ClosureShiftReconcilerTest extends TestCase
{
    private PartnerDeliveryShiftRepository $shiftRepo;
    private WeeklyBasketRepository $weeklyBasketRepo;
    private BasketRepository $basketRepo;
    private NodeDeliveryDate $nodeDeliveryDate;
    private ShiftReconciliationActions $applier;

    protected function setUp(): void
    {
        $this->shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);
        $this->weeklyBasketRepo = $this->createMock(WeeklyBasketRepository::class);
        $this->basketRepo = $this->createMock(BasketRepository::class);
        $this->nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $this->applier = $this->createMock(ShiftReconciliationActions::class);
    }

    public function testNoHaceNadaSiNoEsCierreGlobal(): void
    {
        // Excepción de un solo nodo (traslado), no un cierre global.
        $closure = $this->closure(node: new Node(), cancelled: false, week: new Basket());

        $this->applier->expects($this->never())->method('cancel');
        $this->applier->expects($this->never())->method('cancelSkipIntent');
        $this->applier->expects($this->never())->method('repointTarget');

        $result = $this->reconciler()->reconcile($closure);

        $this->assertSame(['cancelled' => 0, 'repointed' => 0, 'kept' => 0, 'notify' => []], $result);
    }

    public function testSalienteNoRecogeSeLimpia(): void
    {
        $week = new Basket();
        $shift = new PartnerDeliveryShift($this->partner(1), $week, null); // to=null → no recoge
        $this->shiftRepo->method('findAllOutgoingFromBasket')->willReturn([$shift]);
        $this->shiftRepo->method('findAllIncomingToBasket')->willReturn([]);

        $this->applier->expects($this->once())->method('cancelSkipIntent')->with($shift);
        $this->applier->expects($this->never())->method('cancel');

        $result = $this->reconciler()->reconcile($this->closure(null, true, $week));

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame([], $result['notify'], 'el no-recoge no necesita aviso');
    }

    public function testSalienteMoverConDestinoYaRepartidoSeMantiene(): void
    {
        $week = new Basket();
        $to = new Basket();
        $shift = new PartnerDeliveryShift($this->partner(1), $week, $to);
        $this->shiftRepo->method('findAllOutgoingFromBasket')->willReturn([$shift]);
        $this->shiftRepo->method('findAllIncomingToBasket')->willReturn([]);
        // El destino ya tiene entrega materializada → ya repartido/congelado.
        $this->weeklyBasketRepo->method('findOneBy')->willReturn(new WeeklyBasket());

        $this->applier->expects($this->never())->method('cancel');
        $this->applier->expects($this->never())->method('cancelSkipIntent');

        $result = $this->reconciler()->reconcile($this->closure(null, true, $week));

        $this->assertSame(1, $result['kept'], 'no se toca: anular daría doble entrega');
        $this->assertSame(0, $result['cancelled']);
    }

    public function testSalienteMoverConDestinoEnDibujoSeAnula(): void
    {
        $week = new Basket();
        $to = new Basket();
        $shift = new PartnerDeliveryShift($this->partner(42), $week, $to);
        $this->shiftRepo->method('findAllOutgoingFromBasket')->willReturn([$shift]);
        $this->shiftRepo->method('findAllIncomingToBasket')->willReturn([]);
        // Destino aún en dibujo (no materializado).
        $this->weeklyBasketRepo->method('findOneBy')->willReturn(null);

        $this->applier->expects($this->once())->method('cancel')->with($shift);

        $result = $this->reconciler()->reconcile($this->closure(null, true, $week));

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame([42], $result['notify'], 'hay que avisar al socio para que rehaga su cambio');
    }

    public function testEntranteSeReapuntaALaSiguienteOperativa(): void
    {
        $week = new Basket();
        $next = new Basket();
        // Socio SIN nodo (sin grupo): la siguiente operativa es el viernes siguiente sin más.
        $shift = new PartnerDeliveryShift($this->partner(7), new Basket(), $week);
        $this->shiftRepo->method('findAllOutgoingFromBasket')->willReturn([]);
        $this->shiftRepo->method('findAllIncomingToBasket')->willReturn([$shift]);
        $this->basketRepo->method('findNextAfter')->with($week)->willReturn($next);

        $this->applier->expects($this->once())->method('repointTarget')->with($shift, $next);
        $this->applier->expects($this->never())->method('cancel');

        $result = $this->reconciler()->reconcile($this->closure(null, true, $week));

        $this->assertSame(1, $result['repointed']);
    }

    public function testEntranteSinSiguienteOperativaSeAnula(): void
    {
        $week = new Basket();
        $shift = new PartnerDeliveryShift($this->partner(7), new Basket(), $week);
        $this->shiftRepo->method('findAllOutgoingFromBasket')->willReturn([]);
        $this->shiftRepo->method('findAllIncomingToBasket')->willReturn([$shift]);
        $this->basketRepo->method('findNextAfter')->willReturn(null); // no hay más viernes

        $this->applier->expects($this->once())->method('cancel')->with($shift);
        $this->applier->expects($this->never())->method('repointTarget');

        $result = $this->reconciler()->reconcile($this->closure(null, true, $week));

        $this->assertSame(1, $result['cancelled']);
    }

    public function testIntentPorComponenteSeIgnora(): void
    {
        $week = new Basket();
        // Intent POR COMPONENTE (mover solo verdura): fuera de alcance.
        $shift = new PartnerDeliveryShift($this->partner(1), $week, new Basket(), new BasketComponent());
        $this->shiftRepo->method('findAllOutgoingFromBasket')->willReturn([$shift]);
        $this->shiftRepo->method('findAllIncomingToBasket')->willReturn([]);

        $this->applier->expects($this->never())->method('cancel');
        $this->applier->expects($this->never())->method('cancelSkipIntent');

        $result = $this->reconciler()->reconcile($this->closure(null, true, $week));

        $this->assertSame(['cancelled' => 0, 'repointed' => 0, 'kept' => 0, 'notify' => []], $result);
    }

    // --------------------------------------------------------------------- //

    private function reconciler(): ClosureShiftReconciler
    {
        return new ClosureShiftReconciler(
            $this->shiftRepo,
            $this->weeklyBasketRepo,
            $this->basketRepo,
            $this->nodeDeliveryDate,
            $this->applier,
        );
    }

    private function closure(?Node $node, bool $cancelled, Basket $week): DeliveryException
    {
        $closure = $this->createMock(DeliveryException::class);
        $closure->method('getNode')->willReturn($node);
        $closure->method('isCancelled')->willReturn($cancelled);
        $closure->method('getBasket')->willReturn($week);

        return $closure;
    }

    private function partner(int $id): Partner
    {
        $partner = new Partner();
        $ref = new \ReflectionProperty($partner, 'id');
        $ref->setAccessible(true);
        $ref->setValue($partner, $id);

        return $partner;
    }
}
