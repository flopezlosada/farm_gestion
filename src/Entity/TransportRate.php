<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Lo que cuesta llevar cada cesta a un grupo de recogida lejos de la finca, desde una
 * fecha: «a Cascorro, 5 € por cesta desde el 1 de julio de 2026».
 *
 * El precio es POR REPARTO y por cesta, que es como se acuerda con cada grupo; al mes
 * sale de multiplicarlo por los repartos de su modalidad ({@see FeeConcept::deliveriesPerMonth}):
 * a 5 €, la semanal paga 20, la quincenal 10 y la mensual 5. Va por grupo y no por
 * nodo porque dentro de un mismo nodo hay grupos con precios distintos (El Berrueco,
 * en el nodo de Midori, paga 3). Un grupo sin precio no paga transporte.
 *
 * Como las tarifas ({@see FeeRate}), no se cambia: se añade otro con una fecha
 * posterior y así se sabe lo que tocaba pagar cualquier mes. Por eso no tiene setters.
 *
 * Uno por grupo y fecha: dos precios que empiezan el mismo día no dicen cuál vale.
 *
 * @ORM\Table(name="transport_rate", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_transport_rate_group_from", columns={"pickup_group_id", "valid_from"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\TransportRateRepository")
 */
class TransportRate
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="WeeklyBasketGroup")
     * @ORM\JoinColumn(name="pickup_group_id", nullable=false, onDelete="CASCADE")
     */
    private WeeklyBasketGroup $pickupGroup;

    /**
     * Euros por cesta y reparto.
     *
     * @ORM\Column(name="price_per_delivery", type="decimal", precision=8, scale=2)
     */
    private string $pricePerDelivery;

    /**
     * @ORM\Column(name="valid_from", type="date_immutable")
     */
    private \DateTimeImmutable $validFrom;

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
     * @param string $pricePerDelivery Euros por cesta y reparto, «5.00».
     *
     * @throws \InvalidArgumentException Si el precio no es mayor que cero: un grupo que
     *                                   no paga transporte no tiene precio, no uno a cero.
     */
    public function __construct(WeeklyBasketGroup $pickupGroup, string $pricePerDelivery, \DateTimeInterface $validFrom, ?User $createdBy)
    {
        $cents = IssuedInvoice::toCents($pricePerDelivery);
        if ($cents <= 0) {
            throw new \InvalidArgumentException('Un precio de transporte tiene que ser mayor que cero.');
        }
        $this->pickupGroup = $pickupGroup;
        $this->pricePerDelivery = IssuedInvoice::fromCents($cents);
        $this->validFrom = \DateTimeImmutable::createFromInterface($validFrom)->setTime(0, 0);
        $this->createdBy = $createdBy;
    }

    /** El identificador, null hasta que se guarda. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** El grupo de recogida al que se lleva la cesta. */
    public function getPickupGroup(): WeeklyBasketGroup
    {
        return $this->pickupGroup;
    }

    /** Euros por cesta y reparto, «5.00». */
    public function getPricePerDelivery(): string
    {
        return $this->pricePerDelivery;
    }

    /** Desde qué día vale. */
    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    /** Quién lo puso, si se sabe. */
    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /** Cuándo se puso. */
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
