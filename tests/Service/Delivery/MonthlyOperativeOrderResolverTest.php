<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use App\Repository\DeliveryExceptionRepository;
use App\Service\Delivery\MonthlyOperativeOrderResolver;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del MonthlyOperativeOrderResolver. Verifica el orden operativo
 * por nodo apoyándose en NodeDeliveryDate (cadencia + DeliveryException).
 *
 * 8.8b3 introdujo operativeOrderForNode; 8.8e retiró el hardcode legacy y
 * dejó este método como único público.
 */
class MonthlyOperativeOrderResolverTest extends TestCase
{
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
     * Torremocha weekly sin excepciones cuenta los 5 viernes del mes.
     * 8.8e retiró el hardcode de festivos: si admin no registra el festivo
     * como DeliveryException, el resolver asume reparto normal.
     */
    public function testOperativeOrderForNodeTorremochaSinExcepcionesCuenta5Viernes(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $resolver = $this->makeResolver($baskets);

        $this->assertSame(1, $resolver->operativeOrderForNode($baskets[0], $torremocha));  // 1-may
        $this->assertSame(5, $resolver->operativeOrderForNode($baskets[4], $torremocha));  // 29-may
    }

    /**
     * Cuando admin registra una excepción de cancelación pura (global, sin
     * shifted_date) sobre un Basket, el resolver lo trata como no-operativo
     * y devuelve null. Cubre el caso "esta semana no hay reparto".
     */
    public function testOperativeOrderForNodeRespetaCancelacionGlobal(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);

        $cancellation = new DeliveryException();
        $cancellation->setBasket($baskets[0]);
        $cancellation->setShiftedDate(null);

        $resolver = $this->makeResolverWithExceptions($baskets, [1 => $cancellation]);

        $this->assertNull($resolver->operativeOrderForNode($baskets[0], $torremocha));
        // El resto del mes mantiene su orden pero descontado el cancelado.
        $this->assertSame(1, $resolver->operativeOrderForNode($baskets[1], $torremocha));  // 8-may pasa a 1
        $this->assertSame(4, $resolver->operativeOrderForNode($baskets[4], $torremocha));  // 29-may pasa a 4
    }

    /**
     * Cuando admin traslada un reparto a OTRO MES (festivo de fin de mes:
     * 1-may → 30-abr), esa entrega cuenta en el mes de su fecha FÍSICA (abril),
     * no en el del Basket (mayo). Así mayo queda con 4 viernes y un mensual
     * day=4 recoge el 29-may (su último real), no el 22.
     *
     * Regresión del bug que descuadraba los meses de 5 viernes con festivo
     * trasladado al mes anterior: el resolver contaba 5 entregas en mayo y
     * un day=4 caía en el 4º de 5 (22-may) en vez del 29.
     */
    public function testOperativeOrderForNodeTrasladoAMesAnteriorSacaLaEntregaDelMes(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);

        $traslado = new DeliveryException();
        $traslado->setBasket($baskets[0]);                       // 1-may
        $traslado->setShiftedDate(new \DateTime('2026-04-30'));  // → jueves anterior, en abril

        $resolver = $this->makeResolverWithExceptions($baskets, [1 => $traslado]);

        // El 1-may, trasladado al 30-abr, sale de mayo: el orden de mayo
        // arranca en el 8 y el 29 es el 4º (último real del mes).
        $this->assertSame(1, $resolver->operativeOrderForNode($baskets[1], $torremocha));  // 8-may
        $this->assertSame(2, $resolver->operativeOrderForNode($baskets[2], $torremocha));  // 15-may
        $this->assertSame(3, $resolver->operativeOrderForNode($baskets[3], $torremocha));  // 22-may
        $this->assertSame(4, $resolver->operativeOrderForNode($baskets[4], $torremocha));  // 29-may → 4
    }

    /**
     * Sin excepciones, ordersServedBy sirve su posición positiva (contada
     * desde el principio) y su espejo negativo (contado desde el final del
     * mes): en mayo (5 viernes) el 1º sirve +1/-5, el 3º +3/-3, el 5º +5/-1.
     * El negativo es lo que permite emparejar «última semana».
     */
    public function testOrdersServedBySinExcepcionesEsLaPosicionMasSuEspejoNegativo(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $resolver = $this->makeResolver($baskets);

        $this->assertSame([-5, 1], $resolver->ordersServedBy($baskets[0], $torremocha));
        $this->assertSame([-3, 3], $resolver->ordersServedBy($baskets[2], $torremocha));
        $this->assertSame([-1, 5], $resolver->ordersServedBy($baskets[4], $torremocha));
    }

    /**
     * Semántica PEGAJOSA: un cierre NO desplaza a los mensuales posteriores.
     * Cancelado el 8-may (posición 2), el 15-may SIGUE siendo la 3ª (no la 2ª)
     * y además absorbe la posición 2 como fallback del cancelado. El cancelado
     * no sirve nada.
     */
    public function testOrdersServedByCancelacionNoDesplazaYElSiguienteAbsorbeElFallback(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);

        $cancellation = new DeliveryException();
        $cancellation->setBasket($baskets[1]);  // 8-may, posición 2
        $cancellation->setShiftedDate(null);

        $resolver = $this->makeResolverWithExceptions($baskets, [2 => $cancellation]);

        $this->assertSame([-5, 1], $resolver->ordersServedBy($baskets[0], $torremocha));
        $this->assertSame([], $resolver->ordersServedBy($baskets[1], $torremocha));            // cancelado
        $this->assertSame([-4, -3, 2, 3], $resolver->ordersServedBy($baskets[2], $torremocha)); // 15-may: suya + fallback
        $this->assertSame([-2, 4], $resolver->ordersServedBy($baskets[3], $torremocha));       // 22-may NO se mueve
        $this->assertSame([-1, 5], $resolver->ordersServedBy($baskets[4], $torremocha));       // 29-may NO se mueve
    }

    /**
     * Cancelada la ÚLTIMA semana del mes, su posición cae en la última
     * operativa ANTERIOR (el mensual no salta de mes ni se pierde).
     */
    public function testOrdersServedByCancelacionDeLaUltimaHaceFallbackHaciaAtras(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);

        $cancellation = new DeliveryException();
        $cancellation->setBasket($baskets[4]);  // 29-may, posición 5
        $cancellation->setShiftedDate(null);

        $resolver = $this->makeResolverWithExceptions($baskets, [5 => $cancellation]);

        $this->assertSame([], $resolver->ordersServedBy($baskets[4], $torremocha));
        // 22-may absorbe la posición 5 (y su espejo -1): un mensual «última»
        // recogería aquí porque la última semana quedó cancelada.
        $this->assertSame([-2, -1, 4, 5], $resolver->ordersServedBy($baskets[3], $torremocha));
    }

    /**
     * El traslado a otro mes saca la entrega del conteo base igual que del
     * operativo (regresión del festivo 1-may → 30-abr): mayo queda con 4
     * posiciones y day=4 sirve en el 29-may.
     */
    public function testOrdersServedByTrasladoAMesAnteriorSacaLaEntregaDelMes(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);

        $traslado = new DeliveryException();
        $traslado->setBasket($baskets[0]);                       // 1-may
        $traslado->setShiftedDate(new \DateTime('2026-04-30'));  // → jueves anterior, en abril

        $resolver = $this->makeResolverWithExceptions($baskets, [1 => $traslado]);

        // Mayo queda con 4 posiciones: el 8-may es la 1ª (+1/-4) y el 29-may
        // la 4ª, que ahora es la ÚLTIMA del mes (+4/-1).
        $this->assertSame([-4, 1], $resolver->ordersServedBy($baskets[1], $torremocha));
        $this->assertSame([-1, 4], $resolver->ordersServedBy($baskets[4], $torremocha));
    }

    /**
     * «Última semana» (day_month_order = -1) en un mes de 5 viernes recae en
     * el 5º viernes (29-may), NO en el 4º (22-may). Es EL bug reportado por
     * reparto: el antiguo orden 4 caía en el 4º de 5, cuando debía ser el
     * último. Con índice negativo, el -1 sólo lo sirve la última semana.
     */
    public function testUltimaSemanaEnMesDe5ViernesEsElQuinto(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $resolver = $this->makeResolver($baskets);

        $this->assertContains(-1, $resolver->ordersServedBy($baskets[4], $torremocha));     // 29-may (último) SÍ
        $this->assertNotContains(-1, $resolver->ordersServedBy($baskets[3], $torremocha));  // 22-may (4º) NO
    }

    /**
     * El MISMO day_month_order = -1 recae en el 4º viernes cuando el mes sólo
     * tiene 4 (junio 2026: 5, 12, 19, 26). Así una única configuración
     * («última») sigue al último reparto sea cual sea la longitud del mes,
     * que es justo lo que no sabía hacer el orden fijo 4.
     */
    public function testUltimaSemanaEnMesDe4ViernesEsElCuarto(): void
    {
        $baskets = $this->junioBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);
        $resolver = $this->makeResolver($baskets);

        $this->assertContains(-1, $resolver->ordersServedBy($baskets[3], $torremocha));     // 26-jun (4º y último) SÍ
        $this->assertNotContains(-1, $resolver->ordersServedBy($baskets[2], $torremocha));  // 19-jun (3º) NO
        // En un mes de 4, el 4º sirve +4 y -1 a la vez.
        $this->assertSame([-1, 4], $resolver->ordersServedBy($baskets[3], $torremocha));
    }

    /**
     * Si se cancela la última semana del mes, «última» (-1) sigue al fallback
     * hacia atrás (la última operativa anterior), igual que el orden positivo:
     * el mensual no se pierde ni salta de mes.
     */
    public function testUltimaSemanaSigueElFallbackSiSeCancelaLaUltima(): void
    {
        $baskets = $this->mayoBaskets();
        $torremocha = $this->makeNode('Torremocha', 5, Node::CADENCE_WEEKLY);

        $cancellation = new DeliveryException();
        $cancellation->setBasket($baskets[4]);  // 29-may, última
        $cancellation->setShiftedDate(null);

        $resolver = $this->makeResolverWithExceptions($baskets, [5 => $cancellation]);

        $this->assertNotContains(-1, $resolver->ordersServedBy($baskets[4], $torremocha)); // cancelada, no sirve
        $this->assertContains(-1, $resolver->ordersServedBy($baskets[3], $torremocha));    // 22-may absorbe «última»
    }

    /**
     * Devuelve los 5 viernes de mayo 2026 indexados 0..4 para acceso
     * conveniente en los tests.
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
     * Devuelve los 4 viernes de junio 2026 (5, 12, 19, 26) indexados 0..3.
     * Mes de 4 viernes para contrastar la semántica de «última» con mayo (5).
     *
     * @return Basket[]
     */
    private function junioBaskets(): array
    {
        return [
            0 => $this->makeBasket(6, '2026-06-05'),
            1 => $this->makeBasket(7, '2026-06-12'),
            2 => $this->makeBasket(8, '2026-06-19'),
            3 => $this->makeBasket(9, '2026-06-26'),
        ];
    }

    /**
     * Resolver con EM mock que siempre devuelve los baskets dados y
     * NodeDeliveryDate real sin excepciones.
     *
     * @param Basket[] $monthBaskets
     */
    private function makeResolver(array $monthBaskets): MonthlyOperativeOrderResolver
    {
        return $this->makeResolverWithExceptions($monthBaskets, []);
    }

    /**
     * Variante que permite registrar excepciones por basketId.
     *
     * @param Basket[] $monthBaskets
     * @param array<int,DeliveryException> $exceptionsByBasketId Mapa basketId → excepción.
     */
    private function makeResolverWithExceptions(array $monthBaskets, array $exceptionsByBasketId): MonthlyOperativeOrderResolver
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn($monthBaskets);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);

        $exceptionRepo = $this->createMock(DeliveryExceptionRepository::class);
        $exceptionRepo->method('findForBasketAndNode')->willReturnCallback(
            static fn (Basket $basket, Node $node) => $exceptionsByBasketId[$basket->getId()] ?? null
        );

        return new MonthlyOperativeOrderResolver($em, new NodeDeliveryDate($exceptionRepo));
    }

    private function makeBasket(int $id, string $isoDate): Basket
    {
        $basket = new Basket();
        $basket->setDate(new \DateTime($isoDate));

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
