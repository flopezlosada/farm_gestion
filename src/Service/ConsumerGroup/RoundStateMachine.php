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
 *   DRAFT     → OPEN (sólo con {@see open()}, que es lo que avisa) | CANCELLED
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
        // Abrir NO está en la lista: no es un cambio de estado más, es el
        // momento en que se avisa a toda la asociación, y eso sólo lo hace
        // {@see open()}. Si estuviera aquí, el botón genérico de transiciones
        // (gestión y panel del productor) abriría el pedido sin avisar a nadie.
        ConsumerGroupRound::STATUS_DRAFT => [
            ConsumerGroupRound::STATUS_CANCELLED,
        ],
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
            ConsumerGroupRound::STATUS_DELIVERED => $this->markDelivered($round, $now),
            ConsumerGroupRound::STATUS_CANCELLED => $round->setCancelledAt($now),
            default => null,
        };
    }

    /**
     * ¿Se puede abrir ya este borrador a las socias? Hace falta que tenga
     * productos (un aviso de un pedido vacío manda a la gente a una lista sin
     * nada que pedir) y que no haya vencido la fecha de cierre (abriría un
     * pedido en el que ya no se puede apuntar nadie).
     */
    public function canOpen(ConsumerGroupRound $round): bool
    {
        return $round->isDraft()
            && \count($round->getItems()) > 0
            && !$round->isOrdersDeadlinePassed();
    }

    /**
     * Abre el borrador a las socias. NO persiste ni avisa: lo hace quien llama,
     * en este orden —abrir, guardar, avisar—, porque un aviso de un pedido que
     * aún no admite apuntes mandaría a la gente a un «no está abierto».
     *
     * @throws InvalidRoundTransition si no está en borrador o aún no se puede abrir.
     */
    public function open(ConsumerGroupRound $round): void
    {
        if (!$this->canOpen($round)) {
            throw new InvalidRoundTransition('Este pedido no se puede abrir: tiene que estar en borrador, con productos y con la fecha de cierre sin vencer.');
        }

        $round->setStatus(ConsumerGroupRound::STATUS_OPEN);
    }

    /**
     * "Entregado" significa que ha llegado al local — no que las socias lo
     * hayan recogido. La fecha de entrega inicial es orientativa (el
     * productor la mueve constantemente); en cuanto se marca como recibido,
     * ESE es el dato real, así que se sincroniza sola en vez de obligar a
     * editar la ronda aparte para que la hoja de reparto y el calendario de
     * la socia (que casan por esta fecha, no por deliveredAt) no se queden
     * apuntando al día que ya no es.
     */
    private function markDelivered(ConsumerGroupRound $round, \DateTime $now): void
    {
        $round->setDeliveredAt($now);
        $round->setDeliveryDate(clone $now);
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
