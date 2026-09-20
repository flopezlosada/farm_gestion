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
 * Unit test del auto-confirmador: solo actúa cuando la ronda tiene un mínimo
 * automatizable configurado, no está ya confirmada, y el agregado lo alcanza.
 * Los avisos (socias + productor) van SIEMPRE juntos con la confirmación: no
 * tiene sentido confirmar sin que se entere nadie.
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

    public function testNoHaceNadaSinTipoDeMinimo(): void
    {
        $round = new ConsumerGroupRound();

        $aggregator = $this->createMock(OrderAggregator::class);
        $aggregator->expects(self::never())->method('aggregate');

        $notifier = $this->createMock(ConsumerGroupNotifier::class);
        $notifier->expects(self::never())->method('notifyConfirmed');

        $this->confirmer($aggregator, new RoundStateMachine(), $notifier)->checkAndConfirm($round);

        self::assertFalse($round->isConfirmed());
    }

    public function testNoHaceNadaSiYaEstaConfirmada(): void
    {
        $round = new ConsumerGroupRound();
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
        $round = new ConsumerGroupRound();
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
        $round = new ConsumerGroupRound();
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
