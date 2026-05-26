<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Service\Delivery\NodeDeliveryDate;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del NodeDeliveryDate. Verifica el cálculo de fecha física por
 * nodo y la alternancia de semanas operativas en nodos biweekly.
 *
 * Sub-fase 8.8b (2026-05-26).
 */
class NodeDeliveryDateTest extends TestCase
{
    private NodeDeliveryDate $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NodeDeliveryDate();
    }

    public function testTorremochaWeeklyDevuelveMismoViernes(): void
    {
        $node = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $basket = $this->makeBasket('2026-05-08'); // viernes

        $physical = $this->resolver->physicalDateFor($basket, $node);

        $this->assertNotNull($physical);
        $this->assertSame('2026-05-08', $physical->format('Y-m-d'));
    }

    public function testCascorroMiercolesPreviasFuncionaParaSemanaConAncla(): void
    {
        $node = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $basket = $this->makeBasket('2026-05-08'); // viernes-ciclo del ancla

        $physical = $this->resolver->physicalDateFor($basket, $node);

        $this->assertNotNull($physical);
        $this->assertSame('2026-05-06', $physical->format('Y-m-d'));
    }

    public function testCascorroNoRepartLaSemanaSiguiente(): void
    {
        $node = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $basket = $this->makeBasket('2026-05-15'); // 1 semana después del ancla → impar

        $physical = $this->resolver->physicalDateFor($basket, $node);

        $this->assertNull($physical, 'Una semana impar respecto al ancla no debe repartir.');
    }

    public function testCascorroRepartIDosSemanasDespues(): void
    {
        $node = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $basket = $this->makeBasket('2026-05-22'); // 2 semanas después → par

        $physical = $this->resolver->physicalDateFor($basket, $node);

        $this->assertNotNull($physical);
        $this->assertSame('2026-05-20', $physical->format('Y-m-d'));
    }

    public function testCascorroFuncionaEnSemanasPreviasAlAncla(): void
    {
        $node = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $basket = $this->makeBasket('2026-04-24'); // 2 semanas antes del ancla → par

        $physical = $this->resolver->physicalDateFor($basket, $node);

        $this->assertNotNull($physical);
        $this->assertSame('2026-04-22', $physical->format('Y-m-d'));
    }

    public function testDeliversInBasketAtajoBooleano(): void
    {
        $node = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');

        $this->assertTrue($this->resolver->deliversInBasket($this->makeBasket('2026-05-08'), $node));
        $this->assertFalse($this->resolver->deliversInBasket($this->makeBasket('2026-05-15'), $node));
    }

    public function testNodoBiweeklySinAnchorLanzaExcepcion(): void
    {
        $node = $this->makeNode('Roto', 3, Node::CADENCE_BIWEEKLY);

        $this->expectException(\LogicException::class);
        $this->resolver->physicalDateFor($this->makeBasket('2026-05-08'), $node);
    }

    public function testDiasPosterioresAlViernes(): void
    {
        $node = $this->makeNode('Fantasia', 6, Node::CADENCE_WEEKLY); // sábado
        $basket = $this->makeBasket('2026-05-08'); // viernes

        $physical = $this->resolver->physicalDateFor($basket, $node);

        $this->assertNotNull($physical);
        $this->assertSame('2026-05-09', $physical->format('Y-m-d'));
    }

    private function makeNode(string $name, int $weekday, string $cadence, ?string $anchor = null): Node
    {
        $node = new Node();
        $node->setName($name)
            ->setDeliveryWeekday($weekday)
            ->setCadence($cadence);
        if ($anchor !== null) {
            $node->setAnchorDate(new \DateTimeImmutable($anchor));
        }

        return $node;
    }

    private function makeBasket(string $isoDate): Basket
    {
        $basket = new Basket();
        $basket->setDate(new \DateTime($isoDate));

        return $basket;
    }
}
