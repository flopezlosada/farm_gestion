<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un traspaso entre cuentas propias que alguien ha contado por el bot («he pasado 50 €
 * de la caja del local a la de la huerta») y que aún no está en el libro.
 *
 * Vive fuera del libro a propósito: lo entendido de una nota de voz puede estar mal, y
 * un saldo nunca debe incluir algo que nadie ha revisado. En la web se confirma (y
 * entonces sí se anotan los dos apuntes, con {@see \App\Service\Accounting\TransferRecorder})
 * o se descarta. Por eso todo lo que se entendió puede faltar: lo completa quien confirma.
 *
 * Confirmado, se queda enlazado a la mitad que sale, para que el bot sepa que ya está en
 * el libro y no lo dé por deshecho. Si se borra ese traspaso en la web, se va con él.
 *
 * @ORM\Table(name="pending_transfer")
 * @ORM\Entity(repositoryClass="App\Repository\PendingTransferRepository")
 */
class PendingTransfer
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="FinancialAccount")
     * @ORM\JoinColumn(name="from_account_id", nullable=true)
     */
    private ?FinancialAccount $from = null;

    /**
     * @ORM\ManyToOne(targetEntity="FinancialAccount")
     * @ORM\JoinColumn(name="to_account_id", nullable=true)
     */
    private ?FinancialAccount $to = null;

    /**
     * En positivo; el signo lo pone cada mitad al confirmarlo.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private ?string $amount = null;

    /**
     * @ORM\Column(name="transfer_date", type="date_immutable")
     */
    private \DateTimeImmutable $date;

    /**
     * Lo que se dijo o escribió, resumido: será el concepto de los apuntes.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $concept = null;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="sent_by_id", nullable=false, onDelete="CASCADE")
     */
    private User $sentBy;

    /**
     * La mitad que sale, una vez confirmado.
     *
     * @ORM\OneToOne(targetEntity="AccountEntry")
     * @ORM\JoinColumn(name="entry_id", nullable=true, onDelete="CASCADE")
     */
    private ?AccountEntry $entry = null;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @param User               $sentBy Quién lo contó.
     * @param \DateTimeImmutable $date   Cuándo se movió el dinero.
     */
    public function __construct(User $sentBy, \DateTimeImmutable $date)
    {
        $this->sentBy = $sentBy;
        $this->date = $date;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFrom(): ?FinancialAccount
    {
        return $this->from;
    }

    public function getTo(): ?FinancialAccount
    {
        return $this->to;
    }

    /**
     * Origen y destino. Si se entendió la misma cuenta en los dos lados, se queda sin
     * destino: no es un traspaso, y lo decide quien confirma.
     */
    public function setAccounts(?FinancialAccount $from, ?FinancialAccount $to): self
    {
        $this->from = $from;
        $this->to = $to !== null && $to === $from ? null : $to;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    /** Importe en positivo, o null si no se entendió (o no lo era). */
    public function setAmount(?string $amount): self
    {
        $this->amount = $amount !== null && (float) $amount > 0 ? number_format((float) $amount, 2, '.', '') : null;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getConcept(): ?string
    {
        return $this->concept;
    }

    public function setConcept(?string $concept): self
    {
        $concept = $concept !== null ? trim($concept) : '';
        $this->concept = $concept !== '' ? mb_substr($concept, 0, 255) : null;

        return $this;
    }

    public function getSentBy(): User
    {
        return $this->sentBy;
    }

    public function getEntry(): ?AccountEntry
    {
        return $this->entry;
    }

    /** Si ya está en el libro. */
    public function isConfirmed(): bool
    {
        return $this->entry !== null;
    }

    /**
     * Lo da por anotado en el libro.
     *
     * @param AccountEntry $out La mitad que sale, de {@see \App\Service\Accounting\TransferRecorder::record()}.
     *
     * @throws \LogicException Si ya estaba confirmado: sería anotarlo dos veces.
     */
    public function confirm(AccountEntry $out): void
    {
        if ($this->entry !== null) {
            throw new \LogicException('Ese traspaso ya está anotado en el libro.');
        }
        $this->entry = $out;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
