<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\Rule\DeliveryShiftRule;
use App\Service\Delivery\Rule\DeliveryShiftViolation;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del DeliveryShiftValidator. Verifica que itera todas las
 * reglas registradas, recoge las violaciones devueltas y permite
 * separarlas en bloqueantes y bypassables.
 */
class DeliveryShiftValidatorTest extends TestCase
{
    private Partner $partner;
    private Basket $from;
    private Basket $to;

    protected function setUp(): void
    {
        $this->partner = new Partner();
        $this->from = new Basket();
        $this->from->setDate(new \DateTime('2026-06-05'));
        $this->to = new Basket();
        $this->to->setDate(new \DateTime('2026-06-12'));
    }

    /** Construye una regla fake que siempre devuelve la violación dada. */
    private function ruleReturning(?DeliveryShiftViolation $violation): DeliveryShiftRule
    {
        return new class($violation) implements DeliveryShiftRule {
            public function __construct(private readonly ?DeliveryShiftViolation $v) {}
            public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation
            {
                return $this->v;
            }
        };
    }

    public function testSinReglasDevuelveListaVacia(): void
    {
        $validator = new DeliveryShiftValidator([]);
        $this->assertSame([], $validator->validate($this->partner, $this->from, $this->to));
    }

    public function testIteraReglasYRecogeTodasLasViolaciones(): void
    {
        $hard = new DeliveryShiftViolation('r-hard', 'Bloqueante', bypassable: false);
        $soft = new DeliveryShiftViolation('r-soft', 'Soft', bypassable: true);

        $validator = new DeliveryShiftValidator([
            $this->ruleReturning($hard),
            $this->ruleReturning(null), // regla que pasa
            $this->ruleReturning($soft),
        ]);

        $violations = $validator->validate($this->partner, $this->from, $this->to);

        $this->assertCount(2, $violations);
        $this->assertSame($hard, $violations[0]);
        $this->assertSame($soft, $violations[1]);
    }

    public function testBlockingFiltraSoloLasNoBypassables(): void
    {
        $hard = new DeliveryShiftViolation('r-hard', 'Bloqueante', bypassable: false);
        $soft = new DeliveryShiftViolation('r-soft', 'Soft', bypassable: true);

        $validator = new DeliveryShiftValidator([]);
        $blocking = $validator->blocking([$hard, $soft]);

        $this->assertCount(1, $blocking);
        $this->assertSame($hard, $blocking[0]);
    }

    public function testBypassableFiltraSoloLasBypassables(): void
    {
        $hard = new DeliveryShiftViolation('r-hard', 'Bloqueante', bypassable: false);
        $soft1 = new DeliveryShiftViolation('r-soft-1', 'Soft 1', bypassable: true);
        $soft2 = new DeliveryShiftViolation('r-soft-2', 'Soft 2', bypassable: true);

        $validator = new DeliveryShiftValidator([]);
        $bypassable = $validator->bypassable([$hard, $soft1, $soft2]);

        $this->assertCount(2, $bypassable);
        $this->assertContains($soft1, $bypassable);
        $this->assertContains($soft2, $bypassable);
    }

    public function testTodasLasReglasSeEjecutanAunqueAlgunaFalle(): void
    {
        // Si una regla devuelve violación, las siguientes siguen ejecutándose
        // (no se corta). Lo verificamos con una cuenta visible.
        $calls = 0;
        $tracking = new class($calls) implements DeliveryShiftRule {
            public function __construct(public int &$calls) {}
            public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation
            {
                $this->calls++;
                return null;
            }
        };

        $violation = new DeliveryShiftViolation('r1', 'X', bypassable: false);
        $validator = new DeliveryShiftValidator([
            $this->ruleReturning($violation),
            $tracking,
            $this->ruleReturning($violation),
            $tracking,
        ]);

        $validator->validate($this->partner, $this->from, $this->to);
        $this->assertSame(2, $tracking->calls);
    }
}
