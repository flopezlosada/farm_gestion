<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Service\ConsumerGroup\ConsumerGroupEventRecorder;
use App\Service\ConsumerGroup\ConsumerGroupNotifier;
use App\Service\ConsumerGroup\MinimumAutoConfirmer;
use App\Service\ConsumerGroup\OrderAggregator;
use App\Service\ConsumerGroup\RoundStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del auto-confirmador: actúa cuando la ronda no está ya confirmada
 * y, o no pide mínimo, o tiene uno automático que el agregado alcanza. Las
 * socias apuntadas se enteran SIEMPRE de la confirmación; el productor, sólo
 * cuando había un mínimo que alcanzar.
 */
class MinimumAutoConfirmerTest extends TestCase
{
    private function confirmer(OrderAggregator $aggregator, RoundStateMachine $machine, ConsumerGroupNotifier $notifier, ?EntityManagerInterface $em = null): MinimumAutoConfirmer
    {
        return new MinimumAutoConfirmer(
            $aggregator,
            $machine,
            $this->createMock(ConsumerGroupEventRecorder::class),
            $notifier,
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    public function testNoHaceNadaConUnMinimoQueSeConfirmaAMano(): void
    {
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_MANUAL);

        $aggregator = $this->createMock(OrderAggregator::class);
        $aggregator->expects(self::never())->method('aggregate');

        $notifier = $this->createMock(ConsumerGroupNotifier::class);
        $notifier->expects(self::never())->method('notifyConfirmed');

        $this->confirmer($aggregator, new RoundStateMachine(), $notifier)->checkAndConfirm($round);

        self::assertFalse($round->isConfirmed());
    }

    public function testSinMinimoConfirmaYAvisaALasSociasPeroNoAlProductor(): void
    {
        // Al productor su correo le dice que se alcanzó el mínimo: sin mínimo
        // sería falso. A las socias apuntadas sí: su pedido pasa a ser firme.
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_NONE);

        $aggregator = $this->createMock(OrderAggregator::class);
        $aggregator->expects(self::never())->method('aggregate');

        $notifier = $this->createMock(ConsumerGroupNotifier::class);
        $notifier->expects(self::once())->method('notifyConfirmed')->with($round);
        $notifier->expects(self::never())->method('notifyProducerConfirmed');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->confirmer($aggregator, new RoundStateMachine(), $notifier, $em)->checkAndConfirm($round);

        self::assertTrue($round->isConfirmed());
    }

    public function testSinMinimoNoConfirmaUnBorrador(): void
    {
        // Abrir y confirmar son el mismo paso, pero en ese orden: un borrador
        // sin mínimo no puede salir confirmado antes de que nadie lo vea.
        $round = new ConsumerGroupRound();

        $notifier = $this->createMock(ConsumerGroupNotifier::class);
        $notifier->expects(self::never())->method('notifyConfirmed');

        $this->confirmer($this->createMock(OrderAggregator::class), new RoundStateMachine(), $notifier)->checkAndConfirm($round);

        self::assertFalse($round->isConfirmed());
    }

    public function testNoConfirmaUnBorradorAunqueElMinimoSeCumpla(): void
    {
        // La comisión puede apuntar el pedido del local en borrador: eso no
        // puede confirmar (y avisar de que hay que pagar) un pedido que las
        // socias aún no han visto.
        $round = new ConsumerGroupRound();
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('10.00');

        $aggregator = $this->createMock(OrderAggregator::class);
        $aggregator->expects(self::never())->method('aggregate');

        $notifier = $this->createMock(ConsumerGroupNotifier::class);
        $notifier->expects(self::never())->method('notifyConfirmed');

        $this->confirmer($aggregator, new RoundStateMachine(), $notifier)->checkAndConfirm($round);

        self::assertFalse($round->isConfirmed());
    }

    public function testNoHaceNadaSiYaEstaConfirmada(): void
    {
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('10.00');
        $round->setConfirmed(true);

        $aggregator = $this->createMock(OrderAggregator::class);
        $aggregator->expects(self::never())->method('aggregate');

        $this->confirmer($aggregator, new RoundStateMachine(), $this->createMock(ConsumerGroupNotifier::class))
            ->checkAndConfirm($round);
    }

    public function testNoConfirmaSiNoSeAlcanzaElMinimo(): void
    {
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('150.00');

        $aggregator = $this->createMock(OrderAggregator::class);
        $aggregator->method('aggregate')->willReturn(['total' => 50.0, 'participantCount' => 3, 'byItem' => []]);

        $notifier = $this->createMock(ConsumerGroupNotifier::class);
        $notifier->expects(self::never())->method('notifyConfirmed');

        $this->confirmer($aggregator, new RoundStateMachine(), $notifier)->checkAndConfirm($round);

        self::assertFalse($round->isConfirmed());
    }

    public function testConfirmaYAvisaCuandoSeAlcanzaElMinimo(): void
    {
        $round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $round->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);
        $round->setMinimumValue('150.00');

        $aggregator = $this->createMock(OrderAggregator::class);
        $aggregator->method('aggregate')->willReturn(['total' => 200.0, 'participantCount' => 8, 'byItem' => []]);

        $notifier = $this->createMock(ConsumerGroupNotifier::class);
        $notifier->expects(self::once())->method('notifyConfirmed')->with($round);
        $notifier->expects(self::once())->method('notifyProducerConfirmed')->with($round);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->confirmer($aggregator, new RoundStateMachine(), $notifier, $em)->checkAndConfirm($round);

        self::assertTrue($round->isConfirmed());
    }
}
