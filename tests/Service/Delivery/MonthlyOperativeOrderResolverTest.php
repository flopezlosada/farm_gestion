<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Repository\DeliveryExceptionRepository;
use App\Service\Delivery\MonthlyOperativeOrderResolver;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del MonthlyOperativeOrderResolver. Cubre la posición operativa
 * legacy (Torremocha weekly viernes, con hardcode de festivos) y la nueva
 * variante node-aware introducida en 8.8b3 (Cascorro/Midori biweekly).
 */
class MonthlyOperativeOrderResolverTest extends TestCase
{
    /**
     * Mayo 2026: viernes-Basket [1, 8, 15, 22, 29], 1-may festivo en hardcode.
     * El Basket del 29-may debe ser el 4º viernes operativo.
     */
    public function testOperativeOrderInMonthIgnoraFestivoHardcode(): void
    {
        $baskets = $this->mayoBaskets();
        $resolver = $this->makeResolver($baskets);

        $this->assertSame(1, $resolver->operativeOrderInMonth($baskets[1]));  // 8-may
        $this->assertSame(2, $resolver->operativeOrderInMonth($baskets[2]));  // 15-may
        $this->assertSame(3, $resolver->operativeOrderInMonth($baskets[3]));  // 22-may
        $this->assertSame(4, $resolver->operativeOrderInMonth($baskets[4]));  // 29-may
    }

    public function testIsOperativeFalsoParaFestivoHardcode(): void
    {
        $resolver = $this->makeResolver([]);

        $this->assertFalse($resolver->isOperative($this->makeBasket(1, '2026-05-01')));
        $this->assertTrue($resolver->isOperative($this->makeBasket(2, '2026-05-08')));
    }

    /**
     * Cascorro: miércoles, biweekly, anchor 6-may. En mayo 2026 entrega
     * los miércoles 6-may (via Basket 8-may) y 20-may (via Basket 22-may).
     * Así que Basket(8-may) es la 1ª entrega del nodo y Basket(22-may) la 2ª.
     */
    public function testOperativeOrderForNodeCascorroBiweekly(): void
    {
        $baskets = $this->mayoBaskets();
        $cascorro = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $resolver = $this->makeResolver($baskets);

        $this->assertSame(1, $resolver->operativeOrderForNode($baskets[1], $cascorro));  // 8-may
        $this->assertSame(2, $resolver->operativeOrderForNode($baskets[3], $cascorro));  // 22-may
    }

    /**
     * En los Baskets en los que el nodo biweekly no entrega (fuera de fase
     * con su ancla), el método devuelve null — el partner mensual de ese
     * nodo no recoge esa semana.
     */
    public function testOperativeOrderForNodeDevuelveNullSiNodoNoEntrega(): void
    {
        $baskets = $this->mayoBaskets();
        $cascorro = $this->makeNode('Cascorro', 3, Node::CADENCE_BIWEEKLY, '2026-05-06');
        $resolver = $this->makeResolver($baskets);

        // Basket 15-may → miércoles 13-may (1 semana después del ancla → impar).
        $this->assertNull($resolver->operativeOrderForNode($baskets[2], $cascorro));
        // Basket 29-may → miércoles 27-may (3 semanas después del ancla → impar).
        $this->assertNull($resolver->operativeOrderForNode($baskets[4], $cascorro));
    }

    /**
     * Torremocha weekly: el método node-aware NO conoce el hardcode de
     * festivos viernes. Sin DeliveryException en BBDD, cuenta los 5
     * viernes del mes. Esto deja documentado que para Torremocha, mientras
     * los festivos sigan en hardcode, hay que usar operativeOrderInMonth.
     */
    public function testOperativeOrderForNodeTorremochaIgnoraHardcodeFestivos(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $resolver = $this->makeResolver($baskets);

        // Cuenta los 5 viernes naturales — sin saltarse el 1-may.
        $this->assertSame(1, $resolver->operativeOrderForNode($baskets[0], $torremocha));  // 1-may
        $this->assertSame(5, $resolver->operativeOrderForNode($baskets[4], $torremocha));  // 29-may
    }

    /**
     * Devuelve [id => Basket] para los 5 viernes de mayo 2026, indexado
     * por posición natural 0..4 para acceso conveniente en los tests.
     *
     * @return Basket[]
     */
    private function mayoBaskets(): array
    {
        return [
            0 => $this->makeBasket(1, '2026-05-01'),
            1 => $this->makeBasket(2, '2026-05-08'),
            2 => $this->makeBasket(3, '2026-05-15'),
            3 => $this->makeBasket(4, '2026-05-22'),
            4 => $this->makeBasket(5, '2026-05-29'),
        ];
    }

    /**
     * Construye un resolver con EM mock que siempre devuelve los baskets
     * dados, y un NodeDeliveryDate real con repositorio de excepciones
     * vacío (sin overrides de calendario).
     *
     * @param Basket[] $monthBaskets
     */
    private function makeResolver(array $monthBaskets): MonthlyOperativeOrderResolver
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn($monthBaskets);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);

        $exceptionRepo = $this->createMock(DeliveryExceptionRepository::class);
        $exceptionRepo->method('findForBasketAndNode')->willReturn(null);

        return new MonthlyOperativeOrderResolver($em, new NodeDeliveryDate($exceptionRepo));
    }

    private function makeBasket(int $id, string $isoDate): Basket
    {
        $basket = new Basket();
        $basket->setDate(new \DateTime($isoDate));

        // setId no existe en la entidad. Inyectamos id vía reflexión para
        // que getId() devuelva el valor esperado durante el matching.
        $ref = new \ReflectionProperty(Basket::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($basket, $id);

        return $basket;
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
}
