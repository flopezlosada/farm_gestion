<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupRound;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Confirma sola una ronda del grupo de consumo cuando su mínimo estructurado
 * ({@see ConsumerGroupRound::minimumReached()}) se alcanza. Se llama tras
 * CUALQUIER cambio que pueda mover el agregado (apunte de una socia, pedido de
 * la asociación para el local, edición de un pedido desde gestión): es más
 * barato comprobar de más que dejar un caso sin cubrir.
 *
 * Hace su propio flush: se dispara como efecto colateral de una acción cuyo
 * flush principal es sobre otra cosa (el pedido que se acaba de guardar), así
 * que no puede depender de que el caller vuelva a flushear después.
 */
class MinimumAutoConfirmer
{
    public function __construct(
        private readonly OrderAggregator $aggregator,
        private readonly RoundStateMachine $machine,
        private readonly ConsumerGroupEventRecorder $recorder,
        private readonly ConsumerGroupNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Comprueba el mínimo de $round y la confirma + avisa si toca. No hace nada
     * (ni una query de más) si la ronda ya está confirmada o no tiene un mínimo
     * automatizable configurado.
     */
    public function checkAndConfirm(ConsumerGroupRound $round): void
    {
        if (!$this->machine->canConfirm($round) || $round->getMinimumType() === null) {
            return;
        }

        $aggregate = $this->aggregator->aggregate($round);
        if ($round->minimumReached($aggregate) !== true) {
            return;
        }

        $this->machine->confirm($round);
        $this->recorder->record(
            ConsumerGroupEventLog::KIND_ROUND_CONFIRMED,
            $round,
            null,
            sprintf('Pedido confirmado automáticamente: se alcanzó el mínimo (%s).', ConsumerGroupRound::MINIMUM_TYPE_LABELS[$round->getMinimumType()] ?? $round->getMinimumType())
        );
        $this->em->flush();

        $this->notifier->notifyConfirmed($round);
        $this->notifier->notifyProducerConfirmed($round);
    }
}
