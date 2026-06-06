<?php

namespace App\Tests\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Repository\BasketRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Delivery\Rule\WindowRule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del WindowRule con mocks del EntityManager. Verifica que:
 *  - Quincenales solo pueden ir al viernes inmediato siguiente.
 *  - Mensuales solo a viernes del mismo mes.
 *  - Resto de modalidades quedan fuera (semanal, half, only-egg, sin share).
 */
class WindowRuleTest extends TestCase
{
    /**
     * Crea un EntityManager mockeado que devuelve los repos pasados.
     *
     * @param array<class-string, object> $reposByClass mapa Entity::class → repo mock
     */
    private function emWithRepos(array $reposByClass): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $cls) => $reposByClass[$cls] ?? throw new \LogicException("Repo no mockeado: $cls")
        );
        return $em;
    }

    /** Helper: PartnerBasketShare con un BasketShare cuyo id es el dado. */
    private function shareWithType(int $shareTypeId): PartnerBasketShare
    {
        $type = $this->createMock(BasketShare::class);
        $type->method('getId')->willReturn($shareTypeId);

        $share = new PartnerBasketShare();
        $share->setBasketShare($type);
        return $share;
    }

    /** Helper: Basket con id e fecha dada. */
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

    public function testQuincenalAlSiguienteViernesPasa(): void
    {
        $partner = new Partner();
        $from = $this->basket(10, '2026-06-05');
        $to = $this->basket(11, '2026-06-12');

        $shareRepo = $this->createMock(PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn($this->shareWithType(2));

        $basketRepo = $this->createMock(BasketRepository::class);
        $basketRepo->method('findNextAfter')->willReturn($to);

        $rule = new WindowRule($this->emWithRepos([
            PartnerBasketShare::class => $shareRepo,
            Basket::class => $basketRepo,
        ]));

        $this->assertNull($rule->check($partner, $from, $to));
    }

    public function testQuincenalAOtroViernesFallaNoBypassable(): void
    {
        $partner = new Partner();
        $from = $this->basket(10, '2026-06-05');
        $to = $this->basket(12, '2026-06-19'); // dos viernes después, no el inmediato
        $expected = $this->basket(11, '2026-06-12');

        $shareRepo = $this->createMock(PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn($this->shareWithType(2));

        $basketRepo = $this->createMock(BasketRepository::class);
        $basketRepo->method('findNextAfter')->willReturn($expected);

        $rule = new WindowRule($this->emWithRepos([
            PartnerBasketShare::class => $shareRepo,
            Basket::class => $basketRepo,
        ]));

        $v = $rule->check($partner, $from, $to);
        $this->assertNotNull($v);
        $this->assertFalse($v->bypassable, 'WindowRule nunca debe ser bypassable');
    }

    public function testMensualEnElMismoMesPasa(): void
    {
        $partner = new Partner();
        $from = $this->basket(20, '2026-06-05');
        $to = $this->basket(22, '2026-06-26'); // mismo mes

        $shareRepo = $this->createMock(PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn($this->shareWithType(3));

        $basketRepo = $this->createMock(BasketRepository::class);

        $rule = new WindowRule($this->emWithRepos([
            PartnerBasketShare::class => $shareRepo,
            Basket::class => $basketRepo,
        ]));

        $this->assertNull($rule->check($partner, $from, $to));
    }

    public function testMensualEnOtroMesFalla(): void
    {
        $partner = new Partner();
        $from = $this->basket(20, '2026-06-05');
        $to = $this->basket(25, '2026-07-03'); // mes siguiente

        $shareRepo = $this->createMock(PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn($this->shareWithType(3));

        $rule = new WindowRule($this->emWithRepos([
            PartnerBasketShare::class => $shareRepo,
            Basket::class => $this->createMock(BasketRepository::class),
        ]));

        $v = $rule->check($partner, $from, $to);
        $this->assertNotNull($v);
        $this->assertFalse($v->bypassable);
    }

    public function testSemanalQuedaFueraDeLaWindow(): void
    {
        $partner = new Partner();
        $from = $this->basket(30, '2026-06-05');
        $to = $this->basket(31, '2026-06-12');

        $shareRepo = $this->createMock(PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn($this->shareWithType(1)); // semanal

        $rule = new WindowRule($this->emWithRepos([
            PartnerBasketShare::class => $shareRepo,
            Basket::class => $this->createMock(BasketRepository::class),
        ]));

        $v = $rule->check($partner, $from, $to);
        $this->assertNotNull($v);
        $this->assertFalse($v->bypassable);
    }

    public function testPartnerSinShareActivoFalla(): void
    {
        $partner = new Partner();
        $from = $this->basket(40, '2026-06-05');
        $to = $this->basket(41, '2026-06-12');

        $shareRepo = $this->createMock(PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn(null);

        $rule = new WindowRule($this->emWithRepos([
            PartnerBasketShare::class => $shareRepo,
            Basket::class => $this->createMock(BasketRepository::class),
        ]));

        $v = $rule->check($partner, $from, $to);
        $this->assertNotNull($v);
        $this->assertFalse($v->bypassable);
    }
}
