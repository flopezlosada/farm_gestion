<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lo decidido sobre un movimiento del banco que puede ser el pago de pedidos del
 * grupo de consumo: qué pedidos paga, o que no paga ninguno.
 *
 * Existe para que el cruce automático no vuelva sobre lo que ya decidió una persona:
 * sólo casa movimientos SIN fila aquí. Si la tesorera deshace una asignación, la fila
 * se queda sin pedidos y el movimiento vuelve a la lista para elegir a mano, pero el
 * cruce no lo reasigna solo en la siguiente subida de extracto.
 *
 * Estados (no se guardan, salen de los pedidos y de la marca):
 *  - con pedidos: asignado; los pedidos constan pagados por transferencia;
 *  - sin pedidos y descartado: «no es de un pedido» (un donativo, otra cosa);
 *  - sin pedidos ni descarte: pendiente de elegir a mano.
 *
 * Un movimiento puede pagar varios pedidos: una familia que paga dos rondas juntas.
 *
 * @ORM\Table(name="consumer_group_transfer")
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupTransferRepository")
 */
class ConsumerGroupTransfer
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToOne(targetEntity="BankStatementLine")
     * @ORM\JoinColumn(name="bank_line_id", nullable=false, unique=true, onDelete="CASCADE")
     */
    private BankStatementLine $line;

    /**
     * @ORM\OneToMany(targetEntity="ConsumerGroupOrder", mappedBy="transfer")
     * @var Collection<int, ConsumerGroupOrder>
     */
    private Collection $orders;

    /**
     * «No es el pago de ningún pedido»: deja de pedir que se elija.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $dismissed = false;

    /**
     * Si lo asignó el cruce automático (importe y nombre, o nombre aprendido) y no
     * una persona. Para que la tesorera sepa qué revisar.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $automatic = false;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @param BankStatementLine $line el movimiento sobre el que se decide
     */
    public function __construct(BankStatementLine $line)
    {
        $this->line = $line;
        $this->orders = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Da los pedidos por pagados con este movimiento.
     *
     * @param non-empty-list<ConsumerGroupOrder> $orders    los pedidos que paga
     * @param bool                               $automatic si lo decide el cruce y no una persona
     *
     * @throws \InvalidArgumentException si no hay pedidos o alguno no se puede pagar a mano
     */
    public function assign(array $orders, bool $automatic): self
    {
        if ($orders === []) {
            throw new \InvalidArgumentException('Una transferencia se asigna a uno o más pedidos.');
        }
        foreach ($orders as $order) {
            $order->assertPayableByTransfer();
        }
        foreach ($orders as $order) {
            $order->payByTransfer($this);
        }
        $this->dismissed = false;
        $this->automatic = $automatic;

        return $this;
    }

    /** Suelta sus pedidos, que vuelven a pendientes de pago. El movimiento queda por elegir. */
    public function release(): self
    {
        foreach ($this->orders->toArray() as $order) {
            $order->markUnpaid();
        }
        $this->automatic = false;

        return $this;
    }

    /** «No es de ningún pedido»: suelta los que tuviera y deja de pedir que se elija. */
    public function dismiss(): self
    {
        $this->release();
        $this->dismissed = true;

        return $this;
    }

    /** Vuelve a pedir que se elija: se descartó por error. */
    public function reopen(): self
    {
        $this->dismissed = false;

        return $this;
    }

    /** Si falta que una persona elija sus pedidos. */
    public function isPendingDecision(): bool
    {
        return $this->orders->isEmpty() && !$this->dismissed;
    }

    /**
     * Uso interno de {@see ConsumerGroupOrder::payByTransfer()}: el lado inverso de la relación.
     */
    public function addOrder(ConsumerGroupOrder $order): void
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
        }
    }

    /**
     * Uso interno de {@see ConsumerGroupOrder} al soltar el vínculo: el lado inverso de la relación.
     */
    public function removeOrder(ConsumerGroupOrder $order): void
    {
        $this->orders->removeElement($order);
    }

    /** Identificador de la decisión, null hasta que se guarda. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** El movimiento del banco sobre el que se decide. */
    public function getLine(): BankStatementLine
    {
        return $this->line;
    }

    /**
     * Los pedidos que paga este movimiento.
     *
     * @return list<ConsumerGroupOrder>
     */
    public function getOrders(): array
    {
        return array_values($this->orders->toArray());
    }

    /** Si se dijo que no es el pago de ningún pedido. */
    public function isDismissed(): bool
    {
        return $this->dismissed;
    }

    /** Si lo asignó el cruce automático y no una persona. */
    public function isAutomatic(): bool
    {
        return $this->automatic;
    }

    /** Cuándo se tomó la decisión. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
