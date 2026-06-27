<?php

namespace App\Tests\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Repository\BasketRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\AppSettings;
use App\Service\Delivery\Rule\BalanceWithinThresholdRule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del BalanceWithinThresholdRule con mocks. Verifica que tras
 * aplicar el shift hipotéticamente, ningún par de viernes consecutivos
 * supere el umbral; y que la violación es bypassable (admin la puede
 * forzar).
 */
class BalanceWithinThresholdRuleTest extends TestCase
{
    /** Helper: crea un Basket con id y fecha controlados. */
    private function basket(int $id, string $date): Basket
    {
        $b = new class extends Basket {
            public ?int $forcedId = null;
            public function getId(): ?int { return $this->forcedId; }
        };
        $b->forcedId = $id;
        $b->setDate(new \DateTime($date));
        return $b;
    }

    /**
     * EntityManager que devuelve mocks de BasketRepository y
     * WeeklyBasketRepository con los retornos configurados.
     *
     * @param array<int, int> $countsByBasketId
     */
    private function buildEm(
        Basket $prev,
        Basket $from,
        Basket $to,
        Basket $next,
        array $countsByBasketId,
    ): EntityManagerInterface {
        $basketRepo = $this->createMock(BasketRepository::class);
        $basketRepo->method('findPreviousBefore')->willReturn($prev);
        $basketRepo->method('findNextAfter')->willReturn($next);
        $basketRepo->method('findBetweenDates')->willReturn([$prev, $from, $to, $next]);

        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('countPickedInBasket')->willReturnCallback(
            fn (Basket $b) => $countsByBasketId[$b->getId()] ?? 0
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(fn (string $cls) => match ($cls) {
            Basket::class => $basketRepo,
            WeeklyBasket::class => $wbRepo,
            default => throw new \LogicException("Repo no mockeado: $cls"),
        });
        return $em;
    }

    /**
     * Construye la regla con un umbral fijo: el umbral vive ahora en
     * {@see AppSettings::BALANCE_THRESHOLD} y se lee en runtime, así que se
     * mockea AppSettings para que getInt() devuelva el valor del test.
     */
    private function buildRule(EntityManagerInterface $em, int $threshold): BalanceWithinThresholdRule
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')->with(AppSettings::BALANCE_THRESHOLD)->willReturn($threshold);

        return new BalanceWithinThresholdRule($em, $settings);
    }

    public function testPasaCuandoLaDifMaximaQuedaDentroDelUmbral(): void
    {
        $prev = $this->basket(1, '2026-05-29');
        $from = $this->basket(2, '2026-06-05');
        $to   = $this->basket(3, '2026-06-12');
        $next = $this->basket(4, '2026-06-19');

        // Antes del shift: prev=10, from=10, to=10, next=10.
        // Tras shift hipotético: prev=10, from=9, to=11, next=10 → max dif 2.
        $em = $this->buildEm($prev, $from, $to, $next, [1 => 10, 2 => 10, 3 => 10, 4 => 10]);

        $rule = $this->buildRule($em, 3);
        $this->assertNull($rule->check(new Partner(), $from, $to));
    }

    public function testFallaConViolationBypassableCuandoLaDifSuperaElUmbral(): void
    {
        $prev = $this->basket(1, '2026-05-29');
        $from = $this->basket(2, '2026-06-05');
        $to   = $this->basket(3, '2026-06-12');
        $next = $this->basket(4, '2026-06-19');

        // Antes: prev=12, from=8, to=12, next=12. Shift: from=7, to=13.
        // Pares consecutivos tras shift: 12-7=5 (rompe), 7-13=6 (rompe), 13-12=1.
        $em = $this->buildEm($prev, $from, $to, $next, [1 => 12, 2 => 8, 3 => 12, 4 => 12]);

        $rule = $this->buildRule($em, 3);

        $v = $rule->check(new Partner(), $from, $to);
        $this->assertNotNull($v);
        $this->assertTrue($v->bypassable, 'BalanceRule debe ser bypassable por admin');
    }

    public function testElUmbralEsConfigurable(): void
    {
        $prev = $this->basket(1, '2026-05-29');
        $from = $this->basket(2, '2026-06-05');
        $to   = $this->basket(3, '2026-06-12');
        $next = $this->basket(4, '2026-06-19');

        // Tras shift: prev=10, from=8, to=12, next=10. Dif máxima 4 (8-12).
        $em = $this->buildEm($prev, $from, $to, $next, [1 => 10, 2 => 9, 3 => 11, 4 => 10]);

        $ruleEstricto = $this->buildRule($em, 2);
        $this->assertNotNull($ruleEstricto->check(new Partner(), $from, $to), 'Con threshold=2 debe romper');

        $ruleRelajado = $this->buildRule($em, 10);
        $this->assertNull($ruleRelajado->check(new Partner(), $from, $to), 'Con threshold=10 no debe romper');
    }
}
