<?php

namespace App\Tests\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\WeeklyBasketGroup;
use App\Repository\DeliveryExceptionRepository;
use App\Service\AppSettings;
use App\Service\Delivery\DeliveryDeadline;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\Rule\DeadlineRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del DeadlineRule. No toca BBDD — la regla solo necesita la
 * fecha del Basket origen, el Node del partner y compara contra "ahora".
 * El repositorio de excepciones se simula sin excepciones, para aislar la
 * regla del calendario teórico.
 *
 * Sub-fase 8.8b2 (2026-05-26): la regla pasa a ser Node-aware. El
 * deadline se calcula sobre la fecha física del reparto del nodo.
 */
class DeadlineRuleTest extends TestCase
{
    private DeadlineRule $rule;
    private Partner $partner;
    private Basket $toBasket;

    protected function setUp(): void
    {
        $this->rule = $this->ruleWith(daysBefore: 1);
        $this->partner = new Partner();
        // toBasket no influye en esta regla, pero hay que pasar algo.
        $this->toBasket = new Basket();
        $this->toBasket->setDate(new \DateTime('+14 days'));
    }

    /**
     * Construye la regla con una antelación y hora de cierre dadas, mockeando
     * AppSettings (defaults de catálogo = 1 día, 23:59). La hora se pasa como
     * "HH:MM" porque DEADLINE_TIME es una cadena.
     */
    private function ruleWith(int $daysBefore, string $time = '23:59'): DeadlineRule
    {
        $exceptionRepo = $this->createMock(DeliveryExceptionRepository::class);
        $exceptionRepo->method('findForBasketAndNode')->willReturn(null);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')->willReturnMap([
            [AppSettings::DEADLINE_DAYS_BEFORE, $daysBefore],
        ]);
        $settings->method('getTime')->willReturnMap([
            [AppSettings::DEADLINE_TIME, $time],
        ]);

        $deadline = new DeliveryDeadline(new NodeDeliveryDate($exceptionRepo), $settings);

        return new DeadlineRule($deadline);
    }

    public function testPasaSiElViernesEsFuturoYAunNoHaPasadoElDeadline(): void
    {
        $from = new Basket();
        $from->setDate(new \DateTime('+14 days'));

        $this->assertNull($this->rule->check($this->partner, $from, $this->toBasket));
    }

    public function testFallaConViolationBypassableSiElDeadlineYaPaso(): void
    {
        $from = new Basket();
        $from->setDate(new \DateTime('-7 days'));

        $violation = $this->rule->check($this->partner, $from, $this->toBasket);

        $this->assertNotNull($violation);
        $this->assertSame(DeadlineRule::ID, $violation->rule);
        $this->assertTrue($violation->bypassable, 'DeadlineRule debe ser bypassable por admin');
    }

    public function testFallaJustoDespuesDelDeadline(): void
    {
        $from = new Basket();
        $from->setDate(new \DateTime('-1 day'));

        $violation = $this->rule->check($this->partner, $from, $this->toBasket);

        $this->assertNotNull($violation);
        $this->assertTrue($violation->bypassable);
    }

    public function testPasaSiElBasketNoTieneFecha(): void
    {
        $from = new Basket();
        $this->assertNull($this->rule->check($this->partner, $from, $this->toBasket));
    }

    public function testParaPartnerEnNodoTorremochaUsaElViernesComoFisico(): void
    {
        // Torremocha es weekly viernes (=5). Partner asignado.
        $node = (new Node())
            ->setName('Torremocha')
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
        $wbg = (new WeeklyBasketGroup())->setName('Torremocha');
        $wbg->setNode($node);
        $this->partner->setWeeklyBasketGroup($wbg);

        // Basket pasado hace 7 días: jueves 23:59 hace ~6 días, deadline pasado.
        $from = new Basket();
        $from->setDate(new \DateTime('-7 days'));

        $violation = $this->rule->check($this->partner, $from, $this->toBasket);
        $this->assertNotNull($violation, 'Torremocha: deadline 1 día antes del viernes debe haber pasado.');
    }

    public function testLaAntelacionConfiguradaCambiaElResultado(): void
    {
        // Partner sin nodo ⇒ fallback: deadline = fecha del Basket − N días.
        // Reparto dentro de 2 días.
        $from = new Basket();
        $from->setDate(new \DateTime('+2 days'));

        // Antelación 1 día: deadline mañana 23:59 ⇒ aún no ha pasado.
        $this->assertNull(
            $this->ruleWith(daysBefore: 1)->check($this->partner, $from, $this->toBasket),
            'Con cierre 1 día antes de un reparto a 2 días, el plazo sigue abierto.'
        );

        // Antelación 3 días: deadline ayer 23:59 ⇒ ya pasó.
        $this->assertNotNull(
            $this->ruleWith(daysBefore: 3)->check($this->partner, $from, $this->toBasket),
            'Con cierre 3 días antes de un reparto a 2 días, el plazo ya está cerrado.'
        );
    }

    public function testParaPartnerEnNodoBiweeklyDevuelveNullSiNoReparte(): void
    {
        // Cascorro biweekly miércoles, ancla 2026-05-06.
        // Basket viernes 2026-05-15 (1 semana después) → impar → no reparte.
        $node = (new Node())
            ->setName('Cascorro')
            ->setDeliveryWeekday(3)
            ->setCadence(Node::CADENCE_BIWEEKLY)
            ->setAnchorDate(new \DateTimeImmutable('2026-05-06'));
        $wbg = (new WeeklyBasketGroup())->setName('Cascorro');
        $wbg->setNode($node);
        $this->partner->setWeeklyBasketGroup($wbg);

        $from = new Basket();
        $from->setDate(new \DateTime('2026-05-15'));

        // El nodo no reparte ese Basket → no hay deadline → check pasa (null).
        $this->assertNull($this->rule->check($this->partner, $from, $this->toBasket));
    }
}
