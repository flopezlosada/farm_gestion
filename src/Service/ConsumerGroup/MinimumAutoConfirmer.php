<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupRound;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Confirma sola una ronda del grupo de consumo cuando ya no hay nada que
 * esperar: o su mínimo automático ({@see ConsumerGroupRound::minimumReached()})
 * se alcanza, o el productor no pide mínimo. Se llama tras CUALQUIER cambio que
 * pueda mover el agregado o el mínimo (abrir el borrador, apunte de una socia,
 * pedido de la asociación para el local, edición de un pedido o de la ronda
 * desde gestión): es más barato comprobar de más que dejar un caso sin cubrir.
 *
 * SIN MÍNIMO NO SE AVISA AL PRODUCTOR. Su correo le dice que el pedido llegó al
 * mínimo y ya puede darlo por bueno; sin mínimo, lo es desde siempre y ese
 * correo le contaría algo falso. A las socias apuntadas sí: desde ese momento
 * su pedido es firme y se les pide el pago. Al abrir el borrador no suele haber
 * ninguna, así que no les llega un segundo correo encima del de apertura.
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
     * Confirma $round + avisa si toca. No hace nada (ni una query de más) si la
     * ronda ya está confirmada, o si su mínimo se confirma a mano.
     */
    public function checkAndConfirm(ConsumerGroupRound $round): void
    {
        if (!$this->machine->canConfirm($round)) {
            return;
        }

        if ($round->hasNoMinimum()) {
            $this->confirm($round, 'Pedido confirmado: el productor no pide mínimo.');

            return;
        }

        if (!$round->hasAutomaticMinimum() || $round->minimumReached($this->aggregator->aggregate($round)) !== true) {
            return;
        }

        $this->confirm($round, sprintf('Pedido confirmado automáticamente: se alcanzó el mínimo (%s).', $round->getMinimumTypeLabel()));
        $this->notifier->notifyProducerConfirmed($round);
    }

    /**
     * Confirma, deja rastro, guarda y avisa a las socias apuntadas. En este
     * orden: el aviso dice que el pedido es firme, y tiene que serlo ya en la
     * base cuando llegue.
     *
     * @param ConsumerGroupRound $round  la ronda a confirmar
     * @param string             $reason lo que queda en el historial
     */
    private function confirm(ConsumerGroupRound $round, string $reason): void
    {
        $this->machine->confirm($round);
        $this->recorder->record(ConsumerGroupEventLog::KIND_ROUND_CONFIRMED, $round, null, $reason);
        $this->em->flush();

        $this->notifier->notifyConfirmed($round);
    }
}
