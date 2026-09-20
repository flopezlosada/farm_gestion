<?php

namespace App\Tests\Entity;

use App\Entity\ConsumerGroupRound;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Unit test de las dos preguntas que deciden quién puede tocar un pedido
 * colectivo: si la socia puede apuntarse y si la comisión puede gestionarlo.
 *
 * Son distintas a propósito, y confundirlas tiene coste en los dos sentidos:
 * con la de la comisión, la fecha de cierre no cierra nada; con la de la socia,
 * pasado el plazo la comisión no podría ni ajustar la fecha de entrega.
 */
class ConsumerGroupRoundTest extends TestCase
{
    public function testAbiertoSinPlazoAdmiteApuntes(): void
    {
        self::assertTrue($this->round(null)->canReceiveOrders());
    }

    public function testAbiertoConElPlazoPorVencerAdmiteApuntes(): void
    {
        self::assertTrue($this->round(new \DateTime('+2 days'))->canReceiveOrders());
    }

    public function testConElPlazoVencidoYaNoSeApuntaNadie(): void
    {
        self::assertFalse(
            $this->round(new \DateTime('-1 day'))->canReceiveOrders(),
            'La fecha de cierre tiene que cerrar de verdad, no sólo pintarse.'
        );
    }

    public function testElDiaDeCierreTodaviaAdmiteApuntes(): void
    {
        self::assertTrue(
            $this->round(new \DateTime('today'))->canReceiveOrders(),
            'El cierre es un día (sin hora): admite apuntes durante todo ese día.'
        );
    }

    public function testCerradoNoAdmiteApuntesAunqueElPlazoSigaVivo(): void
    {
        $round = $this->round(new \DateTime('+2 days'));
        $round->setStatus(ConsumerGroupRound::STATUS_CLOSED);

        self::assertFalse($round->canReceiveOrders());
    }

    public function testLaComisionSigueGestionandoConElPlazoVencido(): void
    {
        self::assertTrue(
            $this->round(new \DateTime('-1 day'))->canManageOrders(),
            'Pasado el plazo es cuando se habla con el productor y se ajusta la entrega.'
        );
    }

    public function testUnPedidoEntregadoYaNoSeGestiona(): void
    {
        $round = $this->round(null);
        $round->setStatus(ConsumerGroupRound::STATUS_DELIVERED);

        self::assertFalse($round->canManageOrders());
        self::assertFalse($round->canReceiveOrders());
    }

    public function testSinTipoDeMinimoNoHayNadaQueComprobar(): void
    {
        $round = $this->round(null);

        self::assertNull($round->minimumReached(['total' => 1000.0, 'participantCount' => 50]));
    }

    public function testMinimoPorImporteNoAlcanzado(): void
    {
        $round = $this->round(null);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('150.00');

        self::assertFalse($round->minimumReached(['total' => 149.99, 'participantCount' => 20]));
    }

    public function testMinimoPorImporteAlcanzadoExacto(): void
    {
        $round = $this->round(null);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('150.00');

        self::assertTrue($round->minimumReached(['total' => 150.00, 'participantCount' => 1]));
    }

    public function testMinimoPorNumeroDeSociasAlcanzado(): void
    {
        $round = $this->round(null);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_PARTICIPANTS);
        $round->setMinimumValue('10');

        self::assertFalse($round->minimumReached(['total' => 9999.0, 'participantCount' => 9]));
        self::assertTrue($round->minimumReached(['total' => 0.0, 'participantCount' => 10]));
    }

    public function testMinimoPorImporteConSumaDeFlotantesImprecisa(): void
    {
        // Reproduce el caso real: sumar varios round(x, 2) en float puede dar
        // 149.99999999999997 en vez de 150.0 exacto.
        $total = 0.0;
        for ($i = 0; $i < 15; ++$i) {
            $total += round(9.99 + $i * 0.0001, 2);
        }

        $round = $this->round(null);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('149.85');

        self::assertTrue(
            $round->minimumReached(['total' => $total, 'participantCount' => 1]),
            'La comparación en céntimos enteros no debe fallar por ruido de coma flotante.'
        );
    }

    public function testTipoSinValorNoEsUnMinimoAutomaticoValido(): void
    {
        $round = $this->round(null);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        // minimumValue se queda a null a propósito: tipo sin umbral.

        self::assertTrue($this->violatesMinimumTogetherRule($round), 'Tipo sin umbral tiene que violar la regla.');
    }

    public function testValorSinTipoNoEsUnMinimoAutomaticoValido(): void
    {
        $round = $this->round(null);
        $round->setMinimumValue('150.00');
        // minimumType se queda a null a propósito: umbral sin tipo.

        self::assertTrue($this->violatesMinimumTogetherRule($round));
    }

    public function testMinimoConTipoYValorNoViolaLaRegla(): void
    {
        $round = $this->round(null);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('150.00');

        self::assertFalse($this->violatesMinimumTogetherRule($round));
    }

    public function testSinMinimoNoViolaLaRegla(): void
    {
        self::assertFalse($this->violatesMinimumTogetherRule($this->round(null)));
    }

    /**
     * Ejecuta directamente el callback de "tipo y umbral van juntos" (no la
     * validación completa de la entidad, que exigiría rellenar title/producer/
     * etc. sin relación con esta regla) y dice si añadió alguna violación.
     */
    private function violatesMinimumTogetherRule(ConsumerGroupRound $round): bool
    {
        $violated = false;
        $violationBuilder = $this->createMock(\Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturnSelf();
        $violationBuilder->method('addViolation')->willReturnCallback(function () use (&$violated): void {
            $violated = true;
        });

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('buildViolation')->willReturn($violationBuilder);

        $round->validateMinimumTypeAndValueGoTogether($context);

        return $violated;
    }

    private function round(?\DateTime $closesAt): ConsumerGroupRound
    {
        $round = new ConsumerGroupRound();
        $round->setOrdersCloseAt($closesAt);

        return $round;
    }
}
