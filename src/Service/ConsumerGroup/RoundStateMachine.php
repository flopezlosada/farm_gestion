<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;

/**
 * Máquina de estados de una {@see ConsumerGroupRound}. Lógica pura (sin BBDD): decide
 * qué transiciones son válidas y aplica el cambio de estado a la entidad. La
 * persistencia (flush) y los efectos colaterales (email al confirmar) son
 * responsabilidad del controller, no de aquí.
 *
 * Grafo de transiciones:
 *   OPEN      → CLOSED (cerrar apuntes) | CANCELLED (anular)
 *   CLOSED    → CONFIRMED (supera el mínimo) | CANCELLED (no llega) | OPEN (reabrir)
 *   CONFIRMED → DELIVERED (entregada) | CANCELLED (se cae después)
 *   CANCELLED → (terminal)
 *   DELIVERED → (terminal)
 *
 * El mínimo NO se comprueba aquí (su unidad varía y se desconoce): confirmar es una
 * decisión manual de la comisión.
 */
class RoundStateMachine
{
    /**
     * Transiciones permitidas: estado actual => lista de estados destino válidos.
     */
    private const TRANSITIONS = [
        ConsumerGroupRound::STATUS_OPEN => [
            ConsumerGroupRound::STATUS_CLOSED,
            ConsumerGroupRound::STATUS_CANCELLED,
        ],
        ConsumerGroupRound::STATUS_CLOSED => [
            ConsumerGroupRound::STATUS_OPEN,
            ConsumerGroupRound::STATUS_DELIVERED,
            ConsumerGroupRound::STATUS_CANCELLED,
        ],
        ConsumerGroupRound::STATUS_CANCELLED => [],
        ConsumerGroupRound::STATUS_DELIVERED => [],
    ];

    /**
     * ¿Se puede pasar la ronda a $to desde su estado actual?
     */
    public function can(ConsumerGroupRound $round, int $to): bool
    {
        return in_array($to, self::TRANSITIONS[$round->getStatus()] ?? [], true);
    }

    /**
     * Estados a los que la ronda puede transicionar ahora mismo. Para pintar los
     * botones disponibles en la pantalla de gestión.
     *
     * @return int[]
     */
    public function allowedTransitions(ConsumerGroupRound $round): array
    {
        return self::TRANSITIONS[$round->getStatus()] ?? [];
    }

    /**
     * Aplica la transición a $to sobre la ronda. NO persiste: el caller hace flush.
     *
     * @throws InvalidRoundTransition si la transición no está permitida.
     */
    public function transition(ConsumerGroupRound $round, int $to): void
    {
        if (!$this->can($round, $to)) {
            throw new InvalidRoundTransition(sprintf(
                'Transición no permitida: de "%s" a "%s".',
                ConsumerGroupRound::STATUS_LABELS[$round->getStatus()] ?? $round->getStatus(),
                ConsumerGroupRound::STATUS_LABELS[$to] ?? $to
            ));
        }

        $round->setStatus($to);

        // Estampa la fecha del paso (reabrir a OPEN no estampa nada).
        $now = new \DateTime();
        match ($to) {
            ConsumerGroupRound::STATUS_CLOSED => $round->setClosedAt($now),
            ConsumerGroupRound::STATUS_DELIVERED => $round->setDeliveredAt($now),
            ConsumerGroupRound::STATUS_CANCELLED => $round->setCancelledAt($now),
            default => null,
        };
    }

    /**
     * ¿Se puede confirmar el pedido ahora? Confirmar es un flag independiente del
     * plazo: se puede estando ABIERTO o CERRADO (no cancelado/entregado), y solo si
     * no está ya confirmado.
     */
    public function canConfirm(ConsumerGroupRound $round): bool
    {
        return !$round->isConfirmed()
            && in_array($round->getStatus(), [ConsumerGroupRound::STATUS_OPEN, ConsumerGroupRound::STATUS_CLOSED], true);
    }

    /**
     * Confirma el pedido (se ha alcanzado el mínimo, se hará). NO cierra el plazo: el
     * pedido puede seguir abierto a apuntes/pagos. Abre el pago a las socias.
     *
     * @throws InvalidRoundTransition si no se puede confirmar en el estado actual.
     */
    public function confirm(ConsumerGroupRound $round): void
    {
        if (!$this->canConfirm($round)) {
            throw new InvalidRoundTransition('El pedido no se puede confirmar en su estado actual.');
        }

        $round->setConfirmed(true);
        $round->setConfirmedAt(new \DateTime());
    }
}
