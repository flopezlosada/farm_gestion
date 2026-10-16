<?php

namespace App\Entity;

use App\Service\Accounting\Invoice\InvoiceQuarter;
use Doctrine\ORM\Mapping as ORM;

/**
 * Una entrega de un trimestre a la gestoría. Desde que se entrega, lo fechado en ese
 * trimestre (apuntes, facturas recibidas confirmadas, emitidas) no se cambia: la
 * gestoría ya presentó los impuestos con eso, y un cambio callado aquí haría que lo
 * presentado y el libro no coincidieran.
 *
 * Si hay que corregir algo, se reabre: queda escrito quién y cuándo, y el trimestre
 * hay que volver a mandarlo, que es otra entrega (otra fila). Así cada fila cuenta una
 * entrega entera, y el historial no se pierde. Vale lo que diga la más reciente.
 *
 * @ORM\Table(name="accountant_delivery", indexes={
 *     @ORM\Index(name="idx_accountant_delivery_quarter", columns={"year", "quarter"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\AccountantDeliveryRepository")
 */
class AccountantDelivery
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /** @ORM\Column(type="smallint") */
    private int $year;

    /** @ORM\Column(type="smallint") */
    private int $quarter;

    /** @ORM\Column(name="delivered_at", type="datetime_immutable") */
    private \DateTimeImmutable $deliveredAt;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="delivered_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $deliveredBy;

    /** @ORM\Column(name="reopened_at", type="datetime_immutable", nullable=true) */
    private ?\DateTimeImmutable $reopenedAt = null;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="reopened_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $reopenedBy = null;

    /**
     * @param InvoiceQuarter     $quarter El trimestre entregado.
     * @param User|null          $by      Quién lo marca.
     * @param \DateTimeImmutable $at      Cuándo.
     */
    public function __construct(InvoiceQuarter $quarter, ?User $by, \DateTimeImmutable $at)
    {
        $this->year = $quarter->year;
        $this->quarter = $quarter->quarter;
        $this->deliveredBy = $by;
        $this->deliveredAt = $at;
    }

    /**
     * Abre el trimestre para corregirlo. Lo entregado deja de valer: habrá que
     * volver a mandarlo.
     *
     * @throws \LogicException Si ya estaba reabierta.
     */
    public function reopen(?User $by, \DateTimeImmutable $at): void
    {
        if ($this->reopenedAt !== null) {
            throw new \LogicException('Esta entrega ya está reabierta.');
        }
        $this->reopenedAt = $at;
        $this->reopenedBy = $by;
    }

    /** Si esta entrega sigue valiendo, es decir, si el trimestre está cerrado. */
    public function isStanding(): bool
    {
        return $this->reopenedAt === null;
    }

    public function getQuarter(): InvoiceQuarter
    {
        return InvoiceQuarter::of($this->year, $this->quarter);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeliveredAt(): \DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getDeliveredBy(): ?User
    {
        return $this->deliveredBy;
    }

    public function getReopenedAt(): ?\DateTimeImmutable
    {
        return $this->reopenedAt;
    }

    public function getReopenedBy(): ?User
    {
        return $this->reopenedBy;
    }
}
