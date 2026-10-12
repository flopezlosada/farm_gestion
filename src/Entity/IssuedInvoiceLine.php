<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una línea de una factura emitida: qué se cobra, cuánto (sin IVA) y a qué tipo.
 *
 * El IVA va por línea porque una misma factura mezcla tipos: la formación exenta y
 * la estancia al 21 % en la de IMIDRA. Las cuotas no se guardan: salen de las
 * bases agrupadas por tipo ({@see IssuedInvoice::taxBreakdown()}), que es como se
 * calculan en el papel.
 *
 * @ORM\Table(name="issued_invoice_line")
 * @ORM\Entity
 */
class IssuedInvoiceLine
{
    /** Tipos de IVA que se ofrecen; 0 = exenta (formación, art. 20.Uno.9º LIVA). */
    public const RATES = ['0', '4', '10', '21'];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="IssuedInvoice", inversedBy="lines")
     * @ORM\JoinColumn(name="invoice_id", nullable=false, onDelete="CASCADE")
     */
    private ?IssuedInvoice $invoice = null;

    /**
     * Orden en el papel.
     *
     * @ORM\Column(type="smallint")
     */
    private int $position = 0;

    /**
     * @ORM\Column(type="text")
     */
    #[Assert\NotBlank(message: 'Falta qué se cobra.')]
    #[Assert\Length(max: 1000)]
    private string $concept = '';

    /**
     * Base imponible de la línea. Negativa en una rectificativa.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    #[Assert\NotBlank(message: 'Falta el importe.')]
    #[Assert\Regex(pattern: '/^-?\d{1,8}(\.\d{1,2})?$/', message: 'El importe tiene que ser una cantidad con hasta dos decimales.')]
    private string $amount = '0.00';

    /**
     * Tipo de IVA en %. 0 = exenta.
     *
     * @ORM\Column(name="vat_rate", type="decimal", precision=5, scale=2)
     */
    private string $vatRate = '21';

    /**
     * @param string $concept Qué se cobra.
     * @param string $amount  Base, con punto decimal.
     * @param string $vatRate Tipo de IVA en %.
     */
    public function __construct(string $concept = '', string $amount = '0.00', string $vatRate = '21')
    {
        $this->concept = $concept;
        $this->amount = $amount;
        $this->setVatRate($vatRate);
    }

    /** Una copia con el importe cambiado de signo: la base de una rectificativa que anula. */
    public function negated(): self
    {
        return new self($this->concept, IssuedInvoice::fromCents(-IssuedInvoice::toCents($this->amount)), $this->getVatRate());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): ?IssuedInvoice
    {
        return $this->invoice;
    }

    public function setInvoice(?IssuedInvoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->assertDraft();
        $this->position = $position;

        return $this;
    }

    public function getConcept(): string
    {
        return $this->concept;
    }

    public function setConcept(?string $concept): self
    {
        $this->assertDraft();
        $this->concept = trim((string) $concept);

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): self
    {
        $this->assertDraft();
        $this->amount = (string) $amount;

        return $this;
    }

    /**
     * Tipo de IVA sin decimales de relleno: «21», no «21.00» como lo devuelve la
     * base de datos. Se valida aquí y no en la propiedad por eso mismo.
     */
    #[Assert\Choice(choices: self::RATES, message: 'Ese tipo de IVA no existe.')]
    public function getVatRate(): string
    {
        return (string) (0 + (float) $this->vatRate);
    }

    public function setVatRate(?string $vatRate): self
    {
        $this->assertDraft();
        $this->vatRate = (string) $vatRate;

        return $this;
    }

    public function isExempt(): bool
    {
        return (float) $this->vatRate === 0.0;
    }

    /** Una línea de una factura emitida no se cambia: se rectifica. */
    private function assertDraft(): void
    {
        if ($this->invoice?->isIssued()) {
            throw new \LogicException('Una factura emitida no se cambia: se rectifica.');
        }
    }
}
