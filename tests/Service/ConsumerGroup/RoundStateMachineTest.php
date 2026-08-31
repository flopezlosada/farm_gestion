<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Service\ConsumerGroup\InvalidRoundTransition;
use App\Service\ConsumerGroup\RoundStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la máquina de estados del pedido del grupo de consumo. El estado del
 * PLAZO (abierto/cerrado/cancelado/entregado) es un eje; "confirmado" es un FLAG
 * aparte (se puede confirmar estando abierto o cerrado, sin cerrar el plazo).
 */
class RoundStateMachineTest extends TestCase
{
    private RoundStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new RoundStateMachine();
    }

    private function roundIn(int $status): ConsumerGroupRound
    {
        $round = new ConsumerGroupRound();
        $round->setStatus($status);
        return $round;
    }

    /**
     * @dataProvider transicionesValidas
     */
    public function testTransicionValidaCambiaElEstado(int $from, int $to): void
    {
        $round = $this->roundIn($from);

        self::assertTrue($this->machine->can($round, $to));
        $this->machine->transition($round, $to);
        self::assertSame($to, $round->getStatus());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function transicionesValidas(): array
    {
        return [
            'abierto → cerrado'      => [ConsumerGroupRound::STATUS_OPEN, ConsumerGroupRound::STATUS_CLOSED],
            'abierto → cancelado'    => [ConsumerGroupRound::STATUS_OPEN, ConsumerGroupRound::STATUS_CANCELLED],
            'cerrado → reabrir'      => [ConsumerGroupRound::STATUS_CLOSED, ConsumerGroupRound::STATUS_OPEN],
            'cerrado → entregado'    => [ConsumerGroupRound::STATUS_CLOSED, ConsumerGroupRound::STATUS_DELIVERED],
            'cerrado → cancelado'    => [ConsumerGroupRound::STATUS_CLOSED, ConsumerGroupRound::STATUS_CANCELLED],
        ];
    }

    /**
     * @dataProvider transicionesInvalidas
     */
    public function testTransicionInvalidaLanzaYNoCambiaElEstado(int $from, int $to): void
    {
        $round = $this->roundIn($from);

        self::assertFalse($this->machine->can($round, $to));

        try {
            $this->machine->transition($round, $to);
            self::fail('Se esperaba InvalidRoundTransition');
        } catch (InvalidRoundTransition $e) {
            self::assertSame($from, $round->getStatus(), 'El estado no debe cambiar en una transición inválida');
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function transicionesInvalidas(): array
    {
        return [
            'abierto → entregado (salta cierre)' => [ConsumerGroupRound::STATUS_OPEN, ConsumerGroupRound::STATUS_DELIVERED],
            'entregado es terminal'              => [ConsumerGroupRound::STATUS_DELIVERED, ConsumerGroupRound::STATUS_OPEN],
            'cancelado es terminal'              => [ConsumerGroupRound::STATUS_CANCELLED, ConsumerGroupRound::STATUS_OPEN],
        ];
    }

    public function testConfirmarNoCierraElPlazo(): void
    {
        $round = $this->roundIn(ConsumerGroupRound::STATUS_OPEN);

        self::assertTrue($this->machine->canConfirm($round));
        $this->machine->confirm($round);

        self::assertTrue($round->isConfirmed());
        self::assertNotNull($round->getConfirmedAt());
        self::assertSame(ConsumerGroupRound::STATUS_OPEN, $round->getStatus(), 'Confirmar NO cierra el plazo');
    }

    public function testSePuedeConfirmarTambienEstandoCerrado(): void
    {
        $round = $this->roundIn(ConsumerGroupRound::STATUS_CLOSED);

        self::assertTrue($this->machine->canConfirm($round));
    }

    public function testNoSePuedeConfirmarDosVecesNiSiEstaCancelado(): void
    {
        $confirmado = $this->roundIn(ConsumerGroupRound::STATUS_OPEN);
        $confirmado->setConfirmed(true);
        self::assertFalse($this->machine->canConfirm($confirmado));

        $cancelado = $this->roundIn(ConsumerGroupRound::STATUS_CANCELLED);
        self::assertFalse($this->machine->canConfirm($cancelado));

        $this->expectException(InvalidRoundTransition::class);
        $this->machine->confirm($cancelado);
    }
}
