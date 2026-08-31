<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use App\Repository\NodeRepository;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\MonthlyOperativeOrderResolver;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del EggDeliveryResolver. Mockea los dos resolvers auxiliares y
 * verifica la decisión por periodicidad. Cubre el bug que motivó el campo
 * egg_day_month_order: huevos mensuales pintados en viernes equivocado
 * (caso MIRIAM).
 */
class EggDeliveryResolverTest extends TestCase
{
    private BiweeklyCohortResolver&MockObject $biweekly;
    private MonthlyOperativeOrderResolver&MockObject $monthly;
    private NodeRepository&MockObject $nodeRepository;
    private NodeDeliveryDate&MockObject $nodeDeliveryDate;
    private EntityManagerInterface&MockObject $em;
    private EggDeliveryResolver $resolver;
    private Basket $basket;
    private Node $weeklyNode;

    protected function setUp(): void
    {
        $this->biweekly = $this->createMock(BiweeklyCohortResolver::class);
        $this->monthly = $this->createMock(MonthlyOperativeOrderResolver::class);
        $this->nodeRepository = $this->createMock(NodeRepository::class);
        $this->nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->weeklyNode = new Node();
        $this->weeklyNode->setName('Torremocha')->setDeliveryWeekday(5)->setCadence(Node::CADENCE_WEEKLY);
        $this->nodeRepository->method('findOneBy')->willReturn($this->weeklyNode);

        $this->resolver = new EggDeliveryResolver(
            $this->biweekly,
            $this->monthly,
            $this->nodeRepository,
            $this->nodeDeliveryDate,
            $this->em,
        );
        $this->basket = new Basket();
        $this->basket->setDate(new \DateTime('2026-05-15'));
    }

    public function testSinHuevosDevuelveFalse(): void
    {
        $share = new PartnerBasketShare();
        // ni egg_amount ni egg_period seteados
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    public function testSemanalSiempreTrue(): void
    {
        $share = $this->shareConPeriodo(1);
        $this->biweekly->expects($this->never())->method('cohortForBasket');
        $this->monthly->expects($this->never())->method('operativeOrderForNode');
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testQuincenalEnSuCohorteTrue(): void
    {
        $share = $this->shareConPeriodo(2);
        $share->setDeliveryGroup('A');
        $this->biweekly->method('cohortForBasket')->willReturn('A');
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testQuincenalEnCohorteContrariaFalse(): void
    {
        $share = $this->shareConPeriodo(2);
        $share->setDeliveryGroup('A');
        $this->biweekly->method('cohortForBasket')->willReturn('B');
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    public function testQuincenalSinDeliveryGroupFalse(): void
    {
        $share = $this->shareConPeriodo(2);
        // delivery_group=null
        $this->biweekly->expects($this->never())->method('cohortForBasket');
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    /**
     * Quincenal en nodo BIWEEKLY (Madrid: Cascorro/Midori): la quincena la marca
     * la cadencia del propio nodo (anchor), no la cohorte A/B. Sin delivery_group,
     * lleva huevos siempre que el nodo reparte este Basket.
     */
    public function testQuincenalEnNodoBiweeklyEntregaSiElNodoReparte(): void
    {
        $share = $this->shareConPeriodo(2);
        $share->setPartner($this->partnerEnNodoBiweekly());
        // sin delivery_group: la cohorte NO debe consultarse en nodo biweekly
        $this->nodeDeliveryDate->method('deliversInBasket')->willReturn(true);
        $this->biweekly->expects($this->never())->method('cohortForBasket');
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testQuincenalEnNodoBiweeklyNoEntregaSiElNodoNoReparte(): void
    {
        $share = $this->shareConPeriodo(2);
        $share->setPartner($this->partnerEnNodoBiweekly());
        $this->nodeDeliveryDate->method('deliversInBasket')->willReturn(false);
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    /**
     * Cesta mensual + huevos mensuales: huevos van con la cesta. El basket
     * debe SERVIR el dayMonthOrder de la cesta (semántica pegajosa de
     * ordersServedBy); egg_day_month_order se ignora.
     */
    public function testMensualConCestaMensualEnSuOrdenTrue(): void
    {
        $share = $this->shareMensualConCesta(2);
        $this->monthly->method('ordersServedBy')->willReturn([2]);
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    /**
     * El basket que absorbe el fallback de una semana cancelada sirve DOS
     * órdenes: el mensual de cualquiera de los dos recoge aquí.
     */
    public function testMensualConCestaMensualEnOrdenAbsorbidoPorFallbackTrue(): void
    {
        $share = $this->shareMensualConCesta(2);
        $this->monthly->method('ordersServedBy')->willReturn([2, 3]);
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testMensualConCestaMensualEnOrdenDistintoFalse(): void
    {
        $share = $this->shareMensualConCesta(2);
        $this->monthly->method('ordersServedBy')->willReturn([3]);
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    public function testMensualConCestaMensualSiNodoNoEntregaFalse(): void
    {
        $share = $this->shareMensualConCesta(2);
        $this->monthly->method('ordersServedBy')->willReturn([]);
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    /**
     * Cesta NO mensual con huevos mensuales sin egg_day_month_order:
     * fuera del listado para revisión manual.
     */
    public function testQuincenalConHuevosMensualesSinEggDayMonthOrderFalse(): void
    {
        $share = $this->shareConPeriodo(3);
        $share->setBasketShare($this->makeBasketShare(2));
        $share->setDeliveryGroup('B');
        // egg_day_month_order=null
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    /**
     * Huevo mensual de un SEMANAL (ord = N-ésima entrega del socio en el mes),
     * semántica pegajosa: cancelada la 2ª semana, la 3ª SIGUE siendo la 3ª —
     * el huevo de ord=3 no se desplaza a la 4ª (bajo renumeración caería allí).
     */
    public function testHuevoMensualPegajosoNoSeDesplazaPorUnCierreAnterior(): void
    {
        [$semanas, $share] = $this->mesConCierre(cerrada: 1, eggOrder: 3);  // cerrada la 2ª (idx 1)

        $this->assertTrue($this->resolver->delivers($share, $semanas[2]));   // 3ª: suya
        $this->assertFalse($this->resolver->delivers($share, $semanas[3]));  // 4ª: NO se desplaza
    }

    /**
     * Si la semana designada es justo la cancelada, el huevo cae en la
     * SIGUIENTE entrega operativa del mes.
     */
    public function testHuevoMensualEnSemanaCerradaHaceFallbackALaSiguiente(): void
    {
        [$semanas, $share] = $this->mesConCierre(cerrada: 1, eggOrder: 2);

        $this->assertFalse($this->resolver->delivers($share, $semanas[1]));  // cerrada
        $this->assertTrue($this->resolver->delivers($share, $semanas[2]));   // fallback
        $this->assertFalse($this->resolver->delivers($share, $semanas[0]));
    }

    /**
     * Si la cancelada es la ÚLTIMA del mes (o el orden excede la lista), el
     * fallback es la última operativa ANTERIOR: el huevo no salta de mes.
     */
    public function testHuevoMensualEnUltimaSemanaCerradaHaceFallbackHaciaAtras(): void
    {
        [$semanas, $share] = $this->mesConCierre(cerrada: 3, eggOrder: 4);

        $this->assertFalse($this->resolver->delivers($share, $semanas[3]));  // cerrada
        $this->assertTrue($this->resolver->delivers($share, $semanas[2]));   // última operativa
    }

    /**
     * Huevo mensual con egg_day_month_order = -1 («última cesta del mes»):
     * cae en la ÚLTIMA entrega operativa del socio, NO en la primera.
     * Regresión del bug: la fórmula antigua (min(order,count)-1) daba índice
     * -2 y el fallback terminaba resolviendo la primera entrega.
     */
    public function testHuevoMensualUltimaCestaVaEnLaUltimaEntrega(): void
    {
        // Cancelada la 1ª (idx 0); base [X,1,2,3], count 4 → -1 designa idx 3.
        [$semanas, $share] = $this->mesConCierre(cerrada: 0, eggOrder: -1);

        $this->assertTrue($this->resolver->delivers($share, $semanas[3]));   // última: SÍ
        $this->assertFalse($this->resolver->delivers($share, $semanas[1]));  // primera operativa: NO (era el bug)
    }

    /**
     * Si la última cesta del mes está cancelada, «última» (-1) cae en la
     * operativa ANTERIOR (fallback hacia atrás), no salta de mes.
     */
    public function testHuevoMensualUltimaCestaCerradaHaceFallbackHaciaAtras(): void
    {
        [$semanas, $share] = $this->mesConCierre(cerrada: 3, eggOrder: -1);

        $this->assertFalse($this->resolver->delivers($share, $semanas[3]));  // última cerrada
        $this->assertTrue($this->resolver->delivers($share, $semanas[2]));   // anterior operativa
    }

    /**
     * Helper de los tests pegajosos: mes de 4 viernes (junio 2026) con UNA
     * semana cancelada, y un PBS semanal con huevo mensual en la entrega
     * $eggOrder. Cablea los mocks de EM (baskets del mes) y NodeDeliveryDate
     * (base = todas; operativa = todas menos la cerrada).
     *
     * @param int $cerrada Índice 0-based de la semana cancelada.
     * @param int $eggOrder egg_day_month_order del PBS.
     * @return array{0:Basket[],1:PartnerBasketShare}
     */
    private function mesConCierre(int $cerrada, int $eggOrder): array
    {
        $semanas = [];
        foreach (['2026-06-05', '2026-06-12', '2026-06-19', '2026-06-26'] as $i => $iso) {
            $basket = new Basket();
            $basket->setDate(new \DateTime($iso));
            $ref = new \ReflectionProperty(Basket::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($basket, $i + 1);
            $semanas[] = $basket;
        }

        $query = $this->createMock(AbstractQuery::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn($semanas);
        $this->em->method('createQuery')->willReturn($query);

        $cerradaId = $semanas[$cerrada]->getId();
        $this->nodeDeliveryDate->method('baselineDateFor')->willReturnCallback(
            static fn (Basket $b) => \DateTimeImmutable::createFromInterface($b->getDate())
        );
        $this->nodeDeliveryDate->method('deliversInBasket')->willReturnCallback(
            static fn (Basket $b) => $b->getId() !== $cerradaId
        );

        $share = $this->shareConPeriodo(3);
        $share->setBasketShare($this->makeBasketShare(1));  // cesta SEMANAL
        $share->setEggDayMonthOrder($eggOrder);

        return [$semanas, $share];
    }

    /**
     * Helper: PBS mensual con cesta + huevos mensuales y day_month_order dado.
     * Atajo para los tests de "cesta mensual = huevo con la cesta".
     */
    private function shareMensualConCesta(int $dayMonthOrder): PartnerBasketShare
    {
        $share = $this->shareConPeriodo(3);
        $share->setBasketShare($this->makeBasketShare(3));
        $share->setDayMonthOrder($dayMonthOrder);
        return $share;
    }

    /**
     * Helper: BasketShare mock con id (la entidad no expone setId).
     */
    private function makeBasketShare(int $id): BasketShare
    {
        $bs = $this->createMock(BasketShare::class);
        $bs->method('getId')->willReturn($id);
        return $bs;
    }

    /**
     * Helper: construye un PBS con egg_amount y egg_period con el id pedido.
     * EggPeriod no tiene setId, así que usamos un mock parcial.
     */
    private function shareConPeriodo(int $periodoId): PartnerBasketShare
    {
        $period = $this->createMock(EggPeriod::class);
        $period->method('getId')->willReturn($periodoId);
        $amount = new EggAmount();

        $share = new PartnerBasketShare();
        $share->setEggAmount($amount);
        $share->setEggPeriod($period);
        return $share;
    }

    /**
     * Helper: Partner cuyo WeeklyBasketGroup cuelga de un nodo biweekly (Madrid).
     */
    private function partnerEnNodoBiweekly(): Partner
    {
        $node = new Node();
        $node->setName('Cascorro')->setDeliveryWeekday(3)->setCadence(Node::CADENCE_BIWEEKLY);
        $wbg = new WeeklyBasketGroup();
        $wbg->setNode($node);
        $partner = new Partner();
        $partner->setWeeklyBasketGroup($wbg);
        return $partner;
    }
}
