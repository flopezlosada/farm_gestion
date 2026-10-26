<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Un recibo devuelto: el banco cobró el recibo y días después lo devolvió (sin saldo,
 * cuenta mal, la persona lo rechazó), o lo rechazó antes de cobrarlo. Mientras no se
 * decide qué se hace, es una deuda abierta de esa persona.
 *
 * El libro no se toca: la devolución ya entra con el banco (el apunte
 * `bel-liq.rem.devol…` de Fiare), y esto sólo dice de quién es y cómo se cerró. Por
 * eso el apunte es opcional: un recibo rechazado antes del cobro no deja ninguno.
 *
 * Se cierra con una de las salidas de {@see ReturnOutcome}. El estado no se guarda,
 * sale de la salida y de lo enlazado: si se borra el apunte del pago o se quita el
 * recibo del borrador en que se volvía a cobrar, la clave ajena se queda en NULL y la
 * devolución vuelve sola a estar abierta.
 *
 * @ORM\Table(name="remittance_return")
 * @ORM\Entity(repositoryClass="App\Repository\RemittanceReturnRepository")
 */
class RemittanceReturn
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * El recibo devuelto. Uno sólo se devuelve una vez: si se vuelve a cobrar y se
     * devuelve otra vez, es el recibo nuevo el que se devuelve.
     *
     * @ORM\OneToOne(targetEntity="RemittanceLine")
     * @ORM\JoinColumn(name="line_id", nullable=false, unique=true, onDelete="CASCADE")
     */
    private RemittanceLine $line;

    /**
     * El apunte del banco con la devolución, si lo hay.
     *
     * @ORM\OneToOne(targetEntity="AccountEntry")
     * @ORM\JoinColumn(name="entry_id", nullable=true, unique=true, onDelete="SET NULL")
     */
    private ?AccountEntry $entry = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true, enumType=ReturnOutcome::class)
     */
    private ?ReturnOutcome $outcome = null;

    /**
     * Con «pagado aparte», el apunte con que lo pagó.
     *
     * @ORM\ManyToOne(targetEntity="AccountEntry")
     * @ORM\JoinColumn(name="paid_with_id", nullable=true, onDelete="SET NULL")
     */
    private ?AccountEntry $paidWith = null;

    /**
     * Con «volver a cobrar», el recibo nuevo que lo cobra.
     *
     * @ORM\OneToOne(targetEntity="RemittanceLine")
     * @ORM\JoinColumn(name="recharged_in_id", nullable=true, unique=true, onDelete="SET NULL")
     */
    private ?RemittanceLine $rechargedIn = null;

    /**
     * @ORM\Column(name="resolved_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $resolvedAt = null;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="resolved_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $resolvedBy = null;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="created_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $createdBy = null;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @param RemittanceLine    $line  Un recibo de una remesa ya enviada.
     * @param AccountEntry|null $entry El cargo de la devolución, por el mismo importe que el recibo.
     *
     * @throws \InvalidArgumentException Si la remesa no se envió o el apunte no es esa devolución.
     */
    public function __construct(RemittanceLine $line, ?AccountEntry $entry, ?User $by)
    {
        if (!$line->getRemittance()?->isSent()) {
            throw new \InvalidArgumentException('Sólo se devuelve un recibo de una remesa enviada.');
        }
        if ($entry !== null && IssuedInvoice::toCents($entry->getAmount()) !== -IssuedInvoice::toCents($line->getAmount())) {
            throw new \InvalidArgumentException('La devolución tiene que ser un cargo por el importe del recibo.');
        }
        $this->line = $line;
        $this->entry = $entry;
        $this->createdBy = $by;
    }

    /**
     * En qué punto está. Una salida que perdió lo que la enlazaba (el apunte del pago
     * se borró, el recibo nuevo se quitó del borrador) cuenta como lo que queda por
     * hacer, no como resuelta.
     */
    public function getStatus(): ReturnStatus
    {
        return match ($this->outcome) {
            null => ReturnStatus::Pending,
            ReturnOutcome::PaidApart => $this->paidWith !== null ? ReturnStatus::Closed : ReturnStatus::Pending,
            ReturnOutcome::Recharge => match (true) {
                $this->rechargedIn === null => ReturnStatus::ToRecharge,
                (bool) $this->rechargedIn->getRemittance()?->isDraft() => ReturnStatus::Recharging,
                default => ReturnStatus::Closed,
            },
            ReturnOutcome::NotOwed, ReturnOutcome::WrittenOff => ReturnStatus::Closed,
        };
    }

    /** Volver a cobrarlo: queda esperando a que se meta en un borrador de remesa. */
    public function recharge(?User $by): void
    {
        $this->resolve(ReturnOutcome::Recharge, $by);
    }

    /**
     * El recibo nuevo que lo vuelve a cobrar.
     *
     * @throws \LogicException Si no está por volver a cobrar o el recibo no es de un borrador.
     */
    public function rechargeIn(RemittanceLine $line): void
    {
        if ($this->getStatus() !== ReturnStatus::ToRecharge) {
            throw new \LogicException('Esta devolución no está por volver a cobrar.');
        }
        if (!$line->getRemittance()?->isDraft()) {
            throw new \LogicException('Se vuelve a cobrar en un borrador de remesa.');
        }
        $this->rechargedIn = $line;
    }

    /**
     * Lo pagó por otro lado.
     *
     * @param AccountEntry $entry El ingreso con que lo pagó, por el importe del recibo.
     *
     * @throws \InvalidArgumentException Si el apunte no es un ingreso por ese importe.
     */
    public function payApart(AccountEntry $entry, ?User $by): void
    {
        if (IssuedInvoice::toCents($entry->getAmount()) !== IssuedInvoice::toCents($this->line->getAmount())) {
            throw new \InvalidArgumentException('El pago tiene que ser un ingreso por el importe del recibo.');
        }
        $this->resolve(ReturnOutcome::PaidApart, $by);
        $this->paidWith = $entry;
    }

    public function notOwed(?User $by): void
    {
        $this->resolve(ReturnOutcome::NotOwed, $by);
    }

    public function writeOff(?User $by): void
    {
        $this->resolve(ReturnOutcome::WrittenOff, $by);
    }

    /**
     * Deshacer la salida elegida, por si se eligió mal: vuelve a ser una deuda abierta.
     *
     * @throws \LogicException Si ya hay un recibo nuevo que lo vuelve a cobrar.
     */
    public function reopen(): void
    {
        $this->assertReopenable();
        $this->outcome = null;
        $this->paidWith = null;
        $this->resolvedAt = null;
        $this->resolvedBy = null;
    }

    /**
     * Elegir otra salida no puede dejar sin explicar un recibo nuevo que ya lo cobra:
     * se cobraría dos veces. Antes hay que quitarlo del borrador.
     */
    private function assertReopenable(): void
    {
        if ($this->rechargedIn !== null) {
            throw new \LogicException('Ya hay un recibo que lo vuelve a cobrar: quítalo del borrador antes.');
        }
    }

    private function resolve(ReturnOutcome $outcome, ?User $by): void
    {
        $this->assertReopenable();
        $this->outcome = $outcome;
        $this->paidWith = null;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolvedBy = $by;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLine(): RemittanceLine
    {
        return $this->line;
    }

    public function getEntry(): ?AccountEntry
    {
        return $this->entry;
    }

    public function getOutcome(): ?ReturnOutcome
    {
        return $this->outcome;
    }

    public function getPaidWith(): ?AccountEntry
    {
        return $this->paidWith;
    }

    public function getRechargedIn(): ?RemittanceLine
    {
        return $this->rechargedIn;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
