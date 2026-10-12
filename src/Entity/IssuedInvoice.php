<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una factura que emite la asociación: formación, visitas, colaboraciones.
 *
 * Nace como BORRADOR, sin número: se puede cambiar o tirar sin dejar huecos en la
 * numeración. Al EMITIRLA ({@see issue()}) recibe el siguiente número de su serie
 * y año, copia los datos del cliente y ya no se toca: lo que haya que corregir se
 * corrige con una rectificativa ({@see rectificationOf()}), que es lo que pide la ley.
 *
 * Tres series, como las lleva la asociación: la ordinaria («10/2026»), la X para lo
 * que no es formación («X-02/2025») y la de rectificativas («RECT-01/2026»). Cada
 * una cuenta desde 1 cada año.
 *
 * @ORM\Table(name="issued_invoice", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_issued_invoice_number", columns={"series", "year", "number"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\IssuedInvoiceRepository")
 */
class IssuedInvoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';

    /** Formación y lo habitual: «10/2026». */
    public const SERIES_ORDINARY = 'ordinary';

    /** Lo que no es formación: «X-02/2025». */
    public const SERIES_X = 'x';

    /** Rectificativas: «RECT-01/2026». Sólo se llega aquí desde {@see rectificationOf()}. */
    public const SERIES_RECTIFYING = 'rect';

    /** Las series que se eligen al hacer una factura; la de rectificativas no. */
    public const CHOOSABLE_SERIES = [self::SERIES_ORDINARY, self::SERIES_X];

    public const SERIES_LABELS = [
        self::SERIES_ORDINARY => 'Ordinaria',
        self::SERIES_X => 'Serie X',
        self::SERIES_RECTIFYING => 'Rectificativa',
    ];

    public const SOURCE_WEB = 'web';
    public const SOURCE_TELEGRAM = 'telegram';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $status = self::STATUS_DRAFT;

    /**
     * @ORM\Column(type="string", length=10)
     */
    private string $series;

    /**
     * Correlativo dentro de la serie y el año. Null en borrador.
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $number = null;

    /**
     * Año de la numeración: el de la fecha de la factura. Null en borrador.
     *
     * @ORM\Column(type="smallint", nullable=true)
     */
    private ?int $year = null;

    /**
     * Fecha de la factura (la que se imprime). Puede quedar vacía en un borrador.
     *
     * @ORM\Column(name="issue_date", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $issueDate = null;

    /**
     * @ORM\ManyToOne(targetEntity="Customer")
     * @ORM\JoinColumn(name="customer_id", nullable=true, onDelete="RESTRICT")
     */
    private ?Customer $customer = null;

    /**
     * Texto de cabecera de la factura: qué se hizo y cuándo («Inmersión formativa
     * agroecológica… durante los días 23 a 26 de junio»).
     *
     * @ORM\Column(type="text", nullable=true)
     */
    #[Assert\Length(max: 2000)]
    private ?string $description = null;

    /**
     * Por qué una línea al 0 % está exenta. Lo exige la ley en cuanto hay una.
     *
     * @ORM\Column(name="exemption_note", type="text", nullable=true)
     */
    #[Assert\Length(max: 1000)]
    private ?string $exemptionNote = null;

    /**
     * La factura que ésta rectifica. Sólo en la serie de rectificativas.
     *
     * @ORM\ManyToOne(targetEntity="IssuedInvoice")
     * @ORM\JoinColumn(name="rectifies_id", nullable=true, onDelete="RESTRICT")
     */
    private ?IssuedInvoice $rectifies = null;

    /**
     * @var Collection<int, IssuedInvoiceLine>
     *
     * @ORM\OneToMany(targetEntity="IssuedInvoiceLine", mappedBy="invoice", cascade={"persist"}, orphanRemoval=true)
     * @ORM\OrderBy({"position" = "ASC"})
     */
    #[Assert\Valid]
    private Collection $lines;

    /**
     * El apunte del banco en que se cobró (o, en una rectificativa, en que se
     * devolvió). No es parte de la factura, así que se liga y desliga aunque esté
     * emitida. SET NULL: si el apunte se borra, la factura vuelve a estar sin cobrar.
     *
     * @ORM\OneToOne(targetEntity="AccountEntry")
     * @ORM\JoinColumn(name="account_entry_id", nullable=true, unique=true, onDelete="SET NULL")
     */
    private ?AccountEntry $accountEntry = null;

    // Datos del cliente tal como quedaron al emitirla. Antes, null.

    /** @ORM\Column(name="customer_name", type="string", length=255, nullable=true) */
    private ?string $customerName = null;

    /** @ORM\Column(name="customer_tax_id", type="string", length=20, nullable=true) */
    private ?string $customerTaxId = null;

    /** @ORM\Column(name="customer_address", type="string", length=255, nullable=true) */
    private ?string $customerAddress = null;

    /** @ORM\Column(name="customer_postal_code", type="string", length=10, nullable=true) */
    private ?string $customerPostalCode = null;

    /** @ORM\Column(name="customer_town", type="string", length=100, nullable=true) */
    private ?string $customerTown = null;

    /** @ORM\Column(name="customer_province", type="string", length=100, nullable=true) */
    private ?string $customerProvince = null;

    /** @ORM\Column(name="customer_note", type="text", nullable=true) */
    private ?string $customerNote = null;

    /**
     * Por dónde entró el borrador: la web o el bot.
     *
     * @ORM\Column(type="string", length=20)
     */
    private string $source = self::SOURCE_WEB;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="created_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $createdBy = null;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="issued_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $issuedBy = null;

    /**
     * @ORM\Column(name="issued_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $issuedAt = null;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     * @Gedmo\Timestampable(on="create")
     */
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @ORM\Column(name="updated_at", type="datetime")
     * @Gedmo\Timestampable(on="update")
     */
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * Un borrador nuevo.
     *
     * @param string $series Una de {@see CHOOSABLE_SERIES}.
     */
    public function __construct(string $series = self::SERIES_ORDINARY, string $source = self::SOURCE_WEB, ?User $createdBy = null)
    {
        if (!\in_array($series, self::CHOOSABLE_SERIES, true)) {
            throw new \InvalidArgumentException(sprintf('Serie «%s» desconocida; las rectificativas se crean con rectificationOf().', $series));
        }
        $this->series = $series;
        $this->source = $source;
        $this->createdBy = $createdBy;
        $this->lines = new ArrayCollection();
    }

    /**
     * Borrador de rectificativa de una factura ya emitida, al mismo cliente y con
     * sus líneas en negativo: tal cual, la anula entera; para corregir una parte,
     * se dejan sólo las líneas que cambian.
     */
    public static function rectificationOf(self $original, ?User $createdBy = null): self
    {
        if (!$original->isIssued()) {
            throw new \LogicException('Sólo se rectifica una factura emitida.');
        }

        $invoice = new self(self::SERIES_ORDINARY, self::SOURCE_WEB, $createdBy);
        $invoice->series = self::SERIES_RECTIFYING;
        $invoice->rectifies = $original;
        $invoice->customer = $original->customer;
        $invoice->exemptionNote = $original->exemptionNote;
        foreach ($original->lines as $line) {
            $invoice->addLine($line->negated());
        }

        return $invoice;
    }

    /**
     * Lo que impide emitirla, en palabras para quien la está haciendo. Vacío =
     * se puede emitir.
     *
     * @return list<string>
     */
    public function issueProblems(): array
    {
        $problems = [];
        if ($this->customer === null) {
            $problems[] = 'Falta el cliente.';
        } elseif ($this->customer->getTaxId() === null) {
            $problems[] = sprintf('«%s» no tiene CIF: sin él no se le puede emitir una factura.', $this->customer->getName());
        }
        if ($this->issueDate === null) {
            $problems[] = 'Falta la fecha.';
        }
        if ($this->lines->isEmpty()) {
            $problems[] = 'No tiene ninguna línea.';
        }
        if ($this->hasExemptLines() && trim((string) $this->exemptionNote) === '') {
            $problems[] = 'Hay líneas exentas de IVA: falta el texto que explica por qué.';
        }
        if ($this->series === self::SERIES_RECTIFYING && $this->totalCents() >= 0) {
            $problems[] = 'Una rectificativa resta: el total tiene que ser negativo.';
        }

        return $problems;
    }

    /**
     * La emite con el número que le toca: copia los datos del cliente y la deja
     * cerrada. El número lo calcula quien la emite ({@see \App\Service\Accounting\IssuedInvoice\InvoiceIssuer}),
     * que sabe cuál es el último de la serie.
     *
     * @throws \LogicException Si ya estaba emitida o le falta algo.
     */
    public function issue(int $number, ?User $by, \DateTimeImmutable $now): void
    {
        if ($this->isIssued()) {
            throw new \LogicException('Esta factura ya está emitida.');
        }
        if (($problems = $this->issueProblems()) !== []) {
            throw new \LogicException(implode(' ', $problems));
        }

        $this->number = $number;
        $this->year = (int) $this->issueDate->format('Y');
        $this->customerName = $this->customer->getName();
        $this->customerTaxId = $this->customer->getTaxId();
        $this->customerAddress = $this->customer->getAddress();
        $this->customerPostalCode = $this->customer->getPostalCode();
        $this->customerTown = $this->customer->getTown();
        $this->customerProvince = $this->customer->getProvince();
        $this->customerNote = $this->customer->getInvoiceNote();
        $this->issuedBy = $by;
        $this->issuedAt = $now;
        $this->status = self::STATUS_ISSUED;
    }

    /**
     * El número como se imprime: «10/2026», «X-02/2025», «RECT-01/2026». En
     * borrador, «Borrador».
     */
    public function getNumberLabel(): string
    {
        if ($this->number === null || $this->year === null) {
            return 'Borrador';
        }

        return match ($this->series) {
            self::SERIES_X => sprintf('X-%02d/%d', $this->number, $this->year),
            self::SERIES_RECTIFYING => sprintf('RECT-%02d/%d', $this->number, $this->year),
            default => sprintf('%d/%d', $this->number, $this->year),
        };
    }

    /**
     * Los datos del cliente como van en la cabecera, una línea cada cosa: los que
     * se copiaron al emitirla o, en borrador, los de su ficha. La provincia sólo
     * si no es el propio municipio («28013 Madrid», no «28013 Madrid / Madrid»).
     *
     * @return list<string> Vacío si aún no tiene cliente.
     */
    public function customerLines(): array
    {
        $c = $this->customer;
        if (!$this->isIssued() && $c === null) {
            return [];
        }
        [$name, $taxId, $address, $postalCode, $town, $province] = $this->isIssued()
            ? [$this->customerName, $this->customerTaxId, $this->customerAddress, $this->customerPostalCode, $this->customerTown, $this->customerProvince]
            : [$c->getName(), $c->getTaxId(), $c->getAddress(), $c->getPostalCode(), $c->getTown(), $c->getProvince()];

        $place = trim(($postalCode ?? '') . ' ' . ($town ?? ''));
        $lines = [
            (string) $name,
            $taxId !== null ? 'CIF: ' . $taxId : null,
            $address,
            $place !== '' ? $place : null,
            $province !== null && mb_strtolower($province) !== mb_strtolower((string) $town) ? $province : null,
        ];

        return array_values(array_filter($lines, static fn (?string $l): bool => $l !== null && $l !== ''));
    }

    /** Lo que el cliente pide que diga cada factura suya: la copia al emitir o, en borrador, su ficha. */
    public function customerNoteForPrint(): ?string
    {
        return $this->isIssued() ? $this->customerNote : $this->customer?->getInvoiceNote();
    }

    /**
     * Bases e IVA por tipo, como van al pie: la cuota se calcula sobre la suma de
     * las bases de cada tipo, no línea a línea, para que cuadre con el papel.
     *
     * @return list<array{rate: string, base: string, tax: string}> De menor a mayor tipo.
     */
    public function taxBreakdown(): array
    {
        $bases = [];
        foreach ($this->lines as $line) {
            $rate = $line->getVatRate();
            $bases[$rate] = ($bases[$rate] ?? 0) + self::toCents($line->getAmount());
        }
        ksort($bases, \SORT_NUMERIC);

        $rows = [];
        foreach ($bases as $rate => $base) {
            $rows[] = [
                'rate' => (string) $rate,
                'base' => self::fromCents($base),
                'tax' => self::fromCents(self::taxCents($base, (string) $rate)),
            ];
        }

        return $rows;
    }

    /** Suma de las bases. */
    public function getSubtotal(): string
    {
        return self::fromCents(array_sum(array_map(static fn (IssuedInvoiceLine $l): int => self::toCents($l->getAmount()), $this->lines->toArray())));
    }

    /** Suma de las cuotas de IVA. */
    public function getTaxTotal(): string
    {
        return self::fromCents(array_sum(array_map(static fn (array $row): int => self::toCents($row['tax']), $this->taxBreakdown())));
    }

    /** Lo que se cobra: bases más IVA. */
    public function getTotal(): string
    {
        return self::fromCents($this->totalCents());
    }

    public function hasExemptLines(): bool
    {
        return $this->lines->exists(static fn (int $i, IssuedInvoiceLine $l): bool => $l->isExempt());
    }

    /**
     * «1272.5» → 127250. Las cuentas van en céntimos enteros: sumar decimales en
     * coma flotante descuadra el céntimo.
     */
    public static function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** 127250 → «1272.50». */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    /** Cuota de una base a un tipo, redondeada al céntimo (la mitad, hacia arriba en valor absoluto). */
    private static function taxCents(int $baseCents, string $rate): int
    {
        return (int) round($baseCents * (float) $rate / 100);
    }

    private function totalCents(): int
    {
        return self::toCents($this->getSubtotal()) + self::toCents($this->getTaxTotal());
    }

    public function addLine(IssuedInvoiceLine $line): self
    {
        $this->assertDraft();
        if (!$this->lines->contains($line)) {
            $line->setInvoice($this);
            $line->setPosition($this->lines->count());
            $this->lines->add($line);
        }

        return $this;
    }

    public function removeLine(IssuedInvoiceLine $line): self
    {
        $this->assertDraft();
        $this->lines->removeElement($line);

        return $this;
    }

    /** Vuelve a numerar las posiciones en el orden actual (tras quitar o añadir en el formulario). */
    public function renumberLines(): void
    {
        $position = 0;
        foreach ($this->lines as $line) {
            $line->setPosition($position++);
        }
    }

    /** @return Collection<int, IssuedInvoiceLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function getSeries(): string
    {
        return $this->series;
    }

    public function getSeriesLabel(): string
    {
        return self::SERIES_LABELS[$this->series] ?? $this->series;
    }

    /** Sólo en borrador, y nunca a la de rectificativas ni desde ella. */
    public function setSeries(string $series): self
    {
        $this->assertDraft();
        if ($this->series === self::SERIES_RECTIFYING || !\in_array($series, self::CHOOSABLE_SERIES, true)) {
            throw new \InvalidArgumentException('Esa serie no se puede elegir para esta factura.');
        }
        $this->series = $series;

        return $this;
    }

    public function isRectifying(): bool
    {
        return $this->series === self::SERIES_RECTIFYING;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function getIssueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function setIssueDate(?\DateTimeImmutable $issueDate): self
    {
        $this->assertDraft();
        $this->issueDate = $issueDate;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->assertDraft();
        $this->customer = $customer;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->assertDraft();
        $description = trim((string) $description);
        $this->description = $description !== '' ? $description : null;

        return $this;
    }

    public function getExemptionNote(): ?string
    {
        return $this->exemptionNote;
    }

    public function setExemptionNote(?string $exemptionNote): self
    {
        $this->assertDraft();
        $exemptionNote = trim((string) $exemptionNote);
        $this->exemptionNote = $exemptionNote !== '' ? $exemptionNote : null;

        return $this;
    }

    public function getRectifies(): ?self
    {
        return $this->rectifies;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function getCustomerTaxId(): ?string
    {
        return $this->customerTaxId;
    }

    public function getCustomerAddress(): ?string
    {
        return $this->customerAddress;
    }

    public function getCustomerPostalCode(): ?string
    {
        return $this->customerPostalCode;
    }

    public function getCustomerTown(): ?string
    {
        return $this->customerTown;
    }

    public function getCustomerProvince(): ?string
    {
        return $this->customerProvince;
    }

    public function getCustomerNote(): ?string
    {
        return $this->customerNote;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getIssuedBy(): ?User
    {
        return $this->issuedBy;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * Liga la factura al apunte en que se cobró. Sólo una emitida (un borrador no se
     * cobra) y sólo por su importe exacto, con su signo: así una rectificativa casa
     * con la devolución y no con un cobro.
     *
     * @param AccountEntry $entry Apunte del libro que la cobra.
     */
    public function markPaid(AccountEntry $entry): void
    {
        if (!$this->isIssued()) {
            throw new \LogicException('Un borrador no se cobra: hay que emitirlo antes.');
        }
        if (self::toCents($entry->getAmount()) !== $this->totalCents()) {
            throw new \LogicException(sprintf('El apunte es de %s y la factura de %s.', $entry->getAmount(), $this->getTotal()));
        }
        $this->accountEntry = $entry;
    }

    /** Deshace {@see markPaid()}: el apunte no era su cobro. */
    public function unmarkPaid(): void
    {
        $this->accountEntry = null;
    }

    public function isPaid(): bool
    {
        return $this->accountEntry !== null;
    }

    public function getAccountEntry(): ?AccountEntry
    {
        return $this->accountEntry;
    }

    /** Una factura emitida no se cambia: se rectifica. */
    private function assertDraft(): void
    {
        if ($this->isIssued()) {
            throw new \LogicException('Una factura emitida no se cambia: se rectifica.');
        }
    }
}
