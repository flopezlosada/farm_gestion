<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Repository\BasketRepository;
use App\Repository\NodeRepository;
use App\Service\AppSettings;
use App\Service\Delivery\DeliveryDeadline;
use App\Service\Delivery\DeliverySheetSchedule;
use App\Service\Delivery\NodeDeliveryDate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Unit de la regla que decide cuándo sale el listado de cada nodo.
 *
 * Lo que se prueba aquí es justo lo que no se puede comprobar mirando la pantalla:
 * que Madrid (reparto miércoles, cierre martes 23:59) y la Sierra (reparto
 * viernes, cierre jueves) salen cada uno en SU día y no los dos el viernes, que es
 * el error Sierra-céntrico que ya se coló una vez en producción.
 *
 * Con el reloj movido a mano: la alternativa sería esperar a que fuera martes por
 * la noche de verdad.
 */
class DeliverySheetScheduleTest extends TestCase
{
    /** Reparto de Madrid: miércoles 2 de septiembre de 2026. */
    private const MADRID_WED = '2026-09-02';

    /** Reparto de la Sierra: viernes 4 de septiembre de 2026. */
    private const SIERRA_FRI = '2026-09-04';

    /**
     * El miércoles por la mañana ya cerró el plazo de Madrid (martes 23:59) pero
     * no el de la Sierra (jueves 23:59): sale un listado, no dos.
     */
    public function testElMiercolesPorLaMananaSoloSaleElListadoDeMadrid(): void
    {
        $pending = $this->scheduleAt('2026-09-02 07:00:00')->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('Madrid', $pending[0]['node']->getName());
        $this->assertSame(self::MADRID_WED, $pending[0]['physical_date']->format('Y-m-d'));
        $this->assertSame('2026-09-01 23:59', $pending[0]['deadline']->format('Y-m-d H:i'));
    }

    /**
     * El viernes por la mañana le toca a la Sierra. Madrid ya no sale: su reparto
     * fue anteayer y un listado que llega después del reparto no le sirve a nadie.
     */
    public function testElViernesPorLaMananaSoloSaleElListadoDeLaSierra(): void
    {
        $pending = $this->scheduleAt('2026-09-04 07:00:00')->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('Sierra', $pending[0]['node']->getName());
        $this->assertSame(self::SIERRA_FRI, $pending[0]['physical_date']->format('Y-m-d'));
    }

    /**
     * El martes por la mañana no ha cerrado nadie todavía: el plazo de Madrid
     * llega esa misma noche y el listado aún puede cambiar.
     */
    public function testConElPlazoAbiertoNoSaleNada(): void
    {
        $this->assertSame([], $this->scheduleAt('2026-09-01 07:00:00')->pending());
    }

    /**
     * El instante exacto del cierre ya cuenta como cerrado: si el plazo termina a
     * las 23:59 y el reloj marca las 23:59, el listado es definitivo.
     */
    public function testElInstanteDelCierreYaCuenta(): void
    {
        $pending = $this->scheduleAt('2026-09-01 23:59:00')->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('Madrid', $pending[0]['node']->getName());
    }

    /**
     * Pedir una fecha física a mano (--date) manda por encima del plazo: es lo que
     * permite reenviar un listado o probar el correo sin esperar al cierre.
     */
    public function testUnaFechaPedidaAManoIgnoraElPlazo(): void
    {
        $pending = $this->scheduleAt('2026-08-25 10:00:00')
            ->pending(new \DateTimeImmutable(self::SIERRA_FRI));

        $this->assertCount(1, $pending);
        $this->assertSame('Sierra', $pending[0]['node']->getName());
    }

    /**
     * Un nodo que no reparte ese ciclo —cadencia quincenal fuera de fase, o una
     * excepción que cancela el reparto— no aporta listado: NodeDeliveryDate
     * devuelve null y aquí se descarta sin más.
     */
    public function testUnNodoQueNoRepartNoAportaListado(): void
    {
        $madrid = $this->node('Madrid');
        $sierra = $this->node('Sierra');
        $basket = $this->createMock(Basket::class);

        $nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDeliveryDate->method('physicalDateFor')->willReturnCallback(
            static fn (Basket $b, Node $n): ?\DateTimeImmutable => $n === $madrid
                ? new \DateTimeImmutable(self::MADRID_WED)
                : null,
        );

        $schedule = $this->schedule('2026-09-02 07:00:00', [$madrid, $sierra], [$basket], $nodeDeliveryDate);

        $pending = $schedule->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('Madrid', $pending[0]['node']->getName());
    }

    /**
     * Monta la regla con los dos nodos de siempre y un ciclo cuyo reparto cae el
     * miércoles en Madrid y el viernes en la Sierra.
     *
     * @param string $now Instante al que se fija el reloj.
     */
    private function scheduleAt(string $now): DeliverySheetSchedule
    {
        $madrid = $this->node('Madrid');
        $sierra = $this->node('Sierra');

        $nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDeliveryDate->method('physicalDateFor')->willReturnCallback(
            static fn (Basket $b, Node $n): \DateTimeImmutable => new \DateTimeImmutable(
                $n === $madrid ? self::MADRID_WED : self::SIERRA_FRI,
            ),
        );

        return $this->schedule($now, [$madrid, $sierra], [$this->createMock(Basket::class)], $nodeDeliveryDate);
    }

    /**
     * @param string  $now              Instante al que se fija el reloj.
     * @param Node[]  $nodes            Nodos que devuelve el repositorio.
     * @param Basket[] $baskets         Ciclos que devuelve el repositorio.
     * @param NodeDeliveryDate $nodeDeliveryDate Resolución de la fecha física.
     */
    private function schedule(string $now, array $nodes, array $baskets, NodeDeliveryDate $nodeDeliveryDate): DeliverySheetSchedule
    {
        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->method('findBy')->willReturn($nodes);

        $basketRepository = $this->createMock(BasketRepository::class);
        $basketRepository->method('findBetweenDates')->willReturn($baskets);

        // El deadline real, no un mock: la regla que se prueba es precisamente
        // "cerró el día anterior a las 23:59", y con un doble se probaría a sí misma.
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')->willReturn(1);
        $settings->method('getTime')->willReturn('23:59');
        $deadline = new DeliveryDeadline($nodeDeliveryDate, $settings);

        return new DeliverySheetSchedule(
            $basketRepository,
            $nodeRepository,
            $nodeDeliveryDate,
            $deadline,
            $settings,
            new MockClock(new \DateTimeImmutable($now)),
        );
    }

    /**
     * @param string $name Nombre del nodo, que es lo único que se mira en las aserciones.
     */
    private function node(string $name): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getName')->willReturn($name);

        return $node;
    }
}
