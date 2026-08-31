<?php

namespace App\Tests\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Repository\BasketRepository;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Delivery\CohortChoiceBuilder;
use App\Service\Delivery\NodeDeliveryDate;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la restricción de modalidades que impone cada punto de recogida
 * según su cadencia, que es lo que impide dar de alta una cesta que ese punto
 * no puede repartir.
 *
 * La cadencia mensual (El Berrueco, 25-08-2026) es la más estricta: allí sólo
 * caben cestas mensuales y la semana no la elige el socio, la fija el punto.
 */
class CohortChoiceBuilderTest extends TestCase
{
    /**
     * Punto semanal (Torremocha): cabe cualquier modalidad y nada se fuerza.
     */
    public function testPuntoSemanalNoRestringeModalidades(): void
    {
        $result = $this->build($this->makeNode(5, Node::CADENCE_WEEKLY));

        $this->assertNull($result['allowedShareIds']);
        $this->assertNull($result['forcedMonthOrder']);
        $this->assertFalse($result['nodeIsMonthly']);
    }

    /**
     * Punto quincenal (Cascorro, Midori): caben todas menos las de reparto
     * semanal, que necesitarían una entrega cada semana.
     */
    public function testPuntoQuincenalExcluyeLasSemanales(): void
    {
        $node = $this->makeNode(3, Node::CADENCE_BIWEEKLY)->setAnchorDate(new \DateTimeImmutable('2026-05-06'));

        $allowed = $this->build($node)['allowedShareIds'];

        $this->assertNotNull($allowed);
        foreach (BasketShare::IDS_WEEKLY as $weekly) {
            $this->assertNotContains($weekly, $allowed);
        }
        foreach (BasketShare::IDS_BIWEEKLY as $biweekly) {
            $this->assertContains($biweekly, $allowed);
        }
        $this->assertContains(BasketShare::ID_ONLY_EGG, $allowed, 'Sólo-huevos sigue cabiendo en un punto quincenal.');
    }

    /**
     * Punto mensual (El Berrueco): sólo caben las mensuales — normal y
     * compartida. Un punto que abre una semana al mes no puede repartir una
     * cesta semanal ni una quincenal.
     */
    public function testPuntoMensualSoloAdmiteCestasMensuales(): void
    {
        $node = $this->makeNode(3, Node::CADENCE_MONTHLY)->setMonthlyWeek(2);

        $result = $this->build($node);

        $this->assertTrue($result['nodeIsMonthly']);
        $this->assertSame(BasketShare::IDS_MONTHLY, $result['allowedShareIds']);
    }

    /**
     * La semana del socio no se elige en un punto mensual: se copia la del
     * punto, para que no puedan divergir.
     */
    public function testPuntoMensualImponeSuSemanaAlSocio(): void
    {
        $segunda = $this->makeNode(3, Node::CADENCE_MONTHLY)->setMonthlyWeek(2);
        $this->assertSame(2, $this->build($segunda)['forcedMonthOrder']);

        $ultima = $this->makeNode(3, Node::CADENCE_MONTHLY)->setMonthlyWeek(Node::MONTHLY_WEEK_LAST);
        $this->assertSame(Node::MONTHLY_WEEK_LAST, $this->build($ultima)['forcedMonthOrder']);
    }

    /**
     * Las posiciones de mes que se ofrecen a una cesta mensual son sólo las que
     * el punto abre TODOS los meses. En un punto quincenal la "3ª entrega" no
     * está: sólo existe en los meses en que abre tres veces, y elegirla dejaría
     * al socio sin cesta el resto (caso El Berrueco, 2026-08-26).
     */
    public function testLasPosicionesDeMesOfrecidasSonLasQueElPuntoAbreSiempre(): void
    {
        $semanal = $this->build($this->makeNode(5, Node::CADENCE_WEEKLY))['offeredMonthOrders'];
        $this->assertContains(3, $semanal, 'Un punto semanal sí tiene 3ª entrega todos los meses.');

        $quincenal = $this->makeNode(3, Node::CADENCE_BIWEEKLY)->setAnchorDate(new \DateTimeImmutable('2026-05-06'));
        $offered = $this->build($quincenal)['offeredMonthOrders'];
        $this->assertNotContains(3, $offered);
        $this->assertSame([1, 2, Node::MONTHLY_WEEK_LAST], $offered);

        $mensual = $this->makeNode(3, Node::CADENCE_MONTHLY)->setMonthlyWeek(2);
        $this->assertSame([2], $this->build($mensual)['offeredMonthOrders'], 'El punto mensual sólo abre la suya.');
    }

    /**
     * Un socio sin grupo de recogida todavía: nada que restringir hasta que se
     * sepa en qué punto recoge.
     */
    public function testSinNodoNoHayRestriccion(): void
    {
        $result = $this->build(null);

        $this->assertNull($result['allowedShareIds']);
        $this->assertNull($result['offeredMonthOrders']);
        $this->assertNull($result['forcedMonthOrder']);
        $this->assertFalse($result['nodeIsBiweekly']);
        $this->assertFalse($result['nodeIsMonthly']);
    }

    /**
     * @param Node|null $node
     * @return array<string,mixed>
     */
    private function build(?Node $node): array
    {
        $baskets = $this->createMock(BasketRepository::class);
        $baskets->method('findBetweenDates')->willReturn([]);

        return (new CohortChoiceBuilder(
            $baskets,
            $this->createMock(BiweeklyCohortResolver::class),
            $this->createMock(NodeDeliveryDate::class),
        ))->forNode($node);
    }

    /**
     * @param int $weekday Día ISO 1=Lunes..7=Domingo.
     * @param string $cadence Una de Node::CADENCE_*.
     * @return Node
     */
    private function makeNode(int $weekday, string $cadence): Node
    {
        return (new Node())
            ->setName('Punto de prueba')
            ->setDeliveryWeekday($weekday)
            ->setCadence($cadence);
    }
}
