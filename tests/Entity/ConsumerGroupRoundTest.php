<?php

namespace App\Tests\Entity;

use App\Entity\ConsumerGroupRound;
use PHPUnit\Framework\TestCase;

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
            $this->round(new \DateTime('-1 minute'))->canReceiveOrders(),
            'La fecha de cierre tiene que cerrar de verdad, no sólo pintarse.'
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

    private function round(?\DateTime $closesAt): ConsumerGroupRound
    {
        $round = new ConsumerGroupRound();
        $round->setOrdersCloseAt($closesAt);

        return $round;
    }
}
