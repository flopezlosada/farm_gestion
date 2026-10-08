<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una línea del desglose de IVA de una factura: la base que va a un tipo y lo que
 * ese tipo suma. Una factura lleva tantas como tipos mezcle (el pienso al 10 % y la
 * herramienta al 21 % en el mismo papel).
 *
 * Los importes pueden quedar vacíos tras la lectura automática, que a veces no ve
 * una cifra; al confirmar la factura se exigen los tres.
 *
 * @ORM\Table(name="received_invoice_tax_line")
 * @ORM\Entity
 */
class ReceivedInvoiceTaxLine
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="ReceivedInvoice", inversedBy="taxLines")
     * @ORM\JoinColumn(name="invoice_id", nullable=false, onDelete="CASCADE")
     */
    private ?ReceivedInvoice $invoice = null;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    #[Assert\NotNull(message: 'Falta la base.')]
    private ?string $base = null;

    /**
     * Tipo de IVA en tanto por ciento: 21, 10, 4, 0.
     *
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true)
     */
    #[Assert\NotNull(message: 'Falta el tipo de IVA.')]
    #[Assert\Range(min: 0, max: 100)]
    private ?string $rate = null;

    /**
     * Cuota: lo que suma el IVA de esta base.
     *
     * @ORM\Column(name="tax_amount", type="decimal", precision=10, scale=2, nullable=true)
     */
    #[Assert\NotNull(message: 'Falta la cuota de IVA.')]
    private ?string $taxAmount = null;

    /**
     * @param string|null $base      Base imponible.
     * @param string|null $rate      Tipo de IVA en %.
     * @param string|null $taxAmount Cuota.
     */
    public function __construct(?string $base = null, ?string $rate = null, ?string $taxAmount = null)
    {
        $this->base = $base;
        $this->rate = $rate;
        $this->taxAmount = $taxAmount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): ?ReceivedInvoice
    {
        return $this->invoice;
    }

    public function setInvoice(?ReceivedInvoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getBase(): ?string
    {
        return $this->base;
    }

    public function setBase(?string $base): self
    {
        $this->base = $base;

        return $this;
    }

    public function getRate(): ?string
    {
        return $this->rate;
    }

    public function setRate(?string $rate): self
    {
        $this->rate = $rate;

        return $this;
    }

    public function getTaxAmount(): ?string
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(?string $taxAmount): self
    {
        $this->taxAmount = $taxAmount;

        return $this;
    }

    /** Base más cuota: lo que esta línea aporta al total. */
    public function gross(): float
    {
        return (float) $this->base + (float) $this->taxAmount;
    }
}
