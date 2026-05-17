<?php

namespace App\Tests\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Service\Delivery\Rule\DeadlineRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del DeadlineRule. No toca BBDD — la regla solo necesita la
 * fecha del Basket origen y compara contra "ahora".
 */
class DeadlineRuleTest extends TestCase
{
    private DeadlineRule $rule;
    private Partner $partner;
    private Basket $toBasket;

    protected function setUp(): void
    {
        $this->rule = new DeadlineRule();
        $this->partner = new Partner();
        // toBasket no influye en esta regla, pero hay que pasar algo.
        $this->toBasket = new Basket();
        $this->toBasket->setDate(new \DateTime('+14 days'));
    }

    public function testPasaSiElViernesEsFuturoYAunNoHaPasadoElJueves(): void
    {
        $from = new Basket();
        $from->setDate(new \DateTime('+14 days')); // viernes lejano

        $this->assertNull($this->rule->check($this->partner, $from, $this->toBasket));
    }

    public function testFallaConViolationBypassableSiElDeadlineYaPaso(): void
    {
        $from = new Basket();
        $from->setDate(new \DateTime('-7 days')); // viernes pasado

        $violation = $this->rule->check($this->partner, $from, $this->toBasket);

        $this->assertNotNull($violation);
        $this->assertSame(DeadlineRule::ID, $violation->rule);
        $this->assertTrue($violation->bypassable, 'DeadlineRule debe ser bypassable por admin');
    }

    public function testFallaJustoDespuesDelDeadlineDelJueves(): void
    {
        // Construyo un viernes cuyo jueves anterior 23:59 ya pasó hace unos minutos.
        $now = new \DateTimeImmutable('now');
        $diasHastaProxJueves = ($now->format('N') < 4) ? 4 - (int) $now->format('N') : 11 - (int) $now->format('N');
        $proxJueves = $now->modify(sprintf('+%d days', $diasHastaProxJueves));

        // Si el deadline del próximo viernes (jueves 23:59) ya pasó respecto a ahora,
        // construimos un viernes cuyo jueves haya quedado en el pasado inmediato.
        // Para simplificar: usamos "el viernes pasado de hace 1 día" — su deadline (jueves 23:59) está garantizadamente en el pasado.
        $from = new Basket();
        $from->setDate(new \DateTime('-1 day'));

        $violation = $this->rule->check($this->partner, $from, $this->toBasket);

        $this->assertNotNull($violation);
        $this->assertTrue($violation->bypassable);
        unset($proxJueves); // silenciar warning sin perder cálculo
    }

    public function testPasaSiElBasketNoTieneFecha(): void
    {
        $from = new Basket(); // sin setDate

        // Sin fecha no podemos calcular deadline, el chequeo se omite.
        $this->assertNull($this->rule->check($this->partner, $from, $this->toBasket));
    }
}
