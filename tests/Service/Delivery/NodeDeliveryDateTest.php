<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use App\Repository\DeliveryExceptionRepository;
use App\Service\Delivery\NodeDeliveryDate;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del NodeDeliveryDate. Verifica el cálculo de fecha física por
 * nodo, la alternancia de semanas operativas en nodos biweekly y el
 * override por excepciones de calendario.
 *
 * Sub-fase 8.8b (2026-05-26). Excepciones añadidas en 8.8d (2026-05-27).
 */
class NodeDeliveryDateTest extends TestCase
{
    private NodeDeliveryDate $resolver;

    protected function setUp(): void
    {
        // Por defecto, sin excepciones de calendario: el resolver se
        // comporta según el calendario teórico (día + cadencia).
        $this->resolver = $this->makeResolver(null);
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

    public function testExcepcionQueCancelaDevuelveNull(): void
    {
        $node = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $basket = $this->makeBasket('2026-12-25'); // viernes de Navidad

        $exception = new DeliveryException();
        $exception->setBasket($basket);
        $exception->setShiftedDate(null); // sin reparto

        $resolver = $this->makeResolver($exception);

        $this->assertNull($resolver->physicalDateFor($basket, $node));
        $this->assertFalse($resolver->deliversInBasket($basket, $node));
    }

    public function testExcepcionQueTrasladaDevuelveLaFechaMovida(): void
    {
        $node = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $basket = $this->makeBasket('2026-12-25'); // viernes festivo

        $exception = new DeliveryException();
        $exception->setBasket($basket);
        $exception->setShiftedDate(new \DateTimeImmutable('2026-12-23')); // se adelanta al miércoles

        $resolver = $this->makeResolver($exception);

        $physical = $resolver->physicalDateFor($basket, $node);

        $this->assertNotNull($physical);
        $this->assertSame('2026-12-23', $physical->format('Y-m-d'));
    }

    public function testCierreGlobalDesplazaLaCadenciaBiweekly(): void
    {
        // Cascorro (anchor 6-may) sin cierres repartiría 6, 20, 3-jun. Con un
        // cierre global intermedio (festivo: no reparte nadie), la cadencia se
        // corre una semana: pasa a repartir 13-may, 27-may...
        $node = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $resolver = $this->makeResolverWithClosures(1);

        // 15-may (físico 13-may): sin cierre no repartía; con un cierre, sí.
        $physical = $resolver->physicalDateFor($this->makeBasket('2026-05-15'), $node);
        $this->assertNotNull($physical, 'Con un cierre intermedio la cadencia se corre y sí reparte.');
        $this->assertSame('2026-05-13', $physical->format('Y-m-d'));

        // 22-may (físico 20-may): sin cierre repartía; con un cierre, ya no.
        $this->assertNull($resolver->physicalDateFor($this->makeBasket('2026-05-22'), $node));
    }

    public function testOperativeDateForIgnoraLosCierres(): void
    {
        // El picker del CRUD usa operativeDateFor (semanas crudas): aunque haya
        // un cierre, NO desplaza la cadencia teórica, a diferencia de
        // physicalDateFor. Es la garantía que justifica separar ambos caminos.
        $node = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $resolver = $this->makeResolverWithClosures(1);

        $this->assertNull($resolver->operativeDateFor($this->makeBasket('2026-05-15'), $node));

        $physical = $resolver->operativeDateFor($this->makeBasket('2026-05-22'), $node);
        $this->assertNotNull($physical);
        $this->assertSame('2026-05-20', $physical->format('Y-m-d'));
    }

    /**
     * Construye un NodeDeliveryDate con un repositorio de excepciones
     * simulado que siempre devuelve la excepción dada (o null si no hay).
     *
     * @param DeliveryException|null $exception Excepción a devolver, o null.
     * @return NodeDeliveryDate
     */
    private function makeResolver(?DeliveryException $exception): NodeDeliveryDate
    {
        $repo = $this->createMock(DeliveryExceptionRepository::class);
        $repo->method('findForBasketAndNode')->willReturn($exception);

        return new NodeDeliveryDate($repo);
    }

    /**
     * Resolver cuyo repositorio simula un número fijo de cierres globales
     * cancelados en cualquier rango, y ninguna excepción concreta de nodo.
     *
     * @param int $closures Cierres globales a devolver en countGlobalCancellationsBetween.
     * @return NodeDeliveryDate
     */
    private function makeResolverWithClosures(int $closures): NodeDeliveryDate
    {
        $repo = $this->createMock(DeliveryExceptionRepository::class);
        $repo->method('findForBasketAndNode')->willReturn(null);
        $repo->method('countGlobalCancellationsBetween')->willReturn($closures);

        return new NodeDeliveryDate($repo);
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
