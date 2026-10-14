<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Lo que un socix paga de más o de menos sobre la tarifa, en euros al mes y por unos
 * meses: amplía su cuota, pide una reducción porque no llega, o aporta a una
 * iniciativa de la asociación.
 *
 * Va en la ficha de quien PAGA (quien recibe el recibo), no necesariamente de quien
 * recoge la cesta.
 *
 * Por meses enteros: desde un mes y, si es temporal, hasta otro, los dos incluidos.
 * Un ajuste de un solo mes (devolver algo que se cobró de más) tiene los dos iguales.
 *
 * Sólo lo que se paga DE MÁS puede ir a una iniciativa: una reducción no es una
 * donación. Lo garantiza el constructor, así que un ajuste negativo con iniciativa no
 * puede existir.
 *
 * Una vez puesto no se edita: se termina (se le pone mes final) o, si se puso por
 * error, se quita.
 *
 * @ORM\Table(name="fee_adjustment", indexes={
 *     @ORM\Index(name="idx_fee_adjustment_from", columns={"from_month"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\FeeAdjustmentRepository")
 */
class FeeAdjustment
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", nullable=false, onDelete="CASCADE")
     */
    private Partner $partner;

    /**
     * Euros al mes: positivo, paga de más; negativo, de menos.
     *
     * @ORM\Column(type="decimal", precision=8, scale=2)
     */
    private string $amount;

    /**
     * Por qué: «amplía su cuota», «no llega a fin de mes», «se le cobró dos veces en
     * septiembre».
     *
     * @ORM\Column(type="string", length=255)
     */
    private string $reason;

    /**
     * A qué iniciativa va, si es una aportación. Sólo con importe positivo.
     *
     * @ORM\ManyToOne(targetEntity="DonationInitiative")
     * @ORM\JoinColumn(name="initiative_id", nullable=true, onDelete="RESTRICT")
     */
    private ?DonationInitiative $initiative;

    /**
     * El primer mes en que vale, su día 1.
     *
     * @ORM\Column(name="from_month", type="date_immutable")
     */
    private \DateTimeImmutable $fromMonth;

    /**
     * El último mes en que vale, su día 1; null si no tiene fin.
     *
     * @ORM\Column(name="until_month", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $untilMonth;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="created_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $createdBy;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @param string $amount Euros al mes, con signo: «20.00», «-15.00».
     *
     * @throws \InvalidArgumentException Si el importe es cero, la iniciativa va con un
     *                                   importe negativo, falta el motivo o termina
     *                                   antes de empezar.
     */
    public function __construct(
        Partner $partner,
        string $amount,
        string $reason,
        \DateTimeInterface $fromMonth,
        ?\DateTimeInterface $untilMonth = null,
        ?DonationInitiative $initiative = null,
        ?User $createdBy = null,
    ) {
        $cents = IssuedInvoice::toCents($amount);
        $reason = trim($reason);
        if ($cents === 0) {
            throw new \InvalidArgumentException('Un ajuste de cero no cambia nada.');
        }
        if ($initiative !== null && $cents < 0) {
            throw new \InvalidArgumentException('Sólo lo que se paga de más puede ir a una iniciativa.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('Falta el motivo.');
        }

        $this->partner = $partner;
        $this->amount = IssuedInvoice::fromCents($cents);
        $this->reason = $reason;
        $this->initiative = $initiative;
        $this->fromMonth = self::month($fromMonth);
        $this->untilMonth = null;
        $this->createdBy = $createdBy;
        if ($untilMonth !== null) {
            $this->endIn($untilMonth);
        }
    }

    /**
     * Ponerle fin: el último mes en que vale.
     *
     * @throws \InvalidArgumentException Si ese mes es anterior al primero.
     */
    public function endIn(\DateTimeInterface $lastMonth): void
    {
        $lastMonth = self::month($lastMonth);
        if ($lastMonth < $this->fromMonth) {
            throw new \InvalidArgumentException('No puede terminar antes de empezar.');
        }
        $this->untilMonth = $lastMonth;
    }

    /** Si vale en un mes (cualquier día de él). */
    public function appliesTo(\DateTimeInterface $month): bool
    {
        $month = self::month($month);

        return $this->fromMonth <= $month && ($this->untilMonth === null || $month <= $this->untilMonth);
    }

    /** Si ya no vale en ese mes ni en ninguno posterior. */
    public function isOverBy(\DateTimeInterface $month): bool
    {
        return $this->untilMonth !== null && $this->untilMonth < self::month($month);
    }

    /** Si todavía no ha empezado en ese mes. */
    public function isUpcomingIn(\DateTimeInterface $month): bool
    {
        return $this->fromMonth > self::month($month);
    }

    public function isIncrease(): bool
    {
        return IssuedInvoice::toCents($this->amount) > 0;
    }

    /** El día 1 del mes de una fecha, sin hora. */
    public static function month(\DateTimeInterface $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-01'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getInitiative(): ?DonationInitiative
    {
        return $this->initiative;
    }

    public function getFromMonth(): \DateTimeImmutable
    {
        return $this->fromMonth;
    }

    public function getUntilMonth(): ?\DateTimeImmutable
    {
        return $this->untilMonth;
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
