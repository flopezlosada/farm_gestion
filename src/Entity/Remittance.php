<?php

namespace App\Entity;

use App\Service\Accounting\Remittance\RemittancePeriodText;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Una remesa de recibos domiciliados: lo que cada mes se sube a Fiare para cobrar
 * las cuotas. Es el mismo Excel que hoy se hace a mano (una fila por recibo, con las
 * columnas que pide el cuaderno 19 del banco), pero guardado: así se sabe qué se le
 * cobró a quién cada mes, y el mes siguiente sale del anterior en vez de teclearse.
 *
 * Nace de dos formas. IMPORTADA: se sube uno de los Excel ya enviados, y queda tal
 * cual como histórico. PREPARADA: la app copia la última remesa del mismo tipo en
 * BORRADOR para el periodo siguiente ({@see nextFrom()}); se revisa, se corrige y,
 * al darla por enviada, ya no se toca.
 *
 * Lo que se cobra lo dice la remesa, no las cestas: en la base faltan datos que sí
 * están en el Excel (los mandatos, quién paga por quién), así que las cestas sólo
 * sirven para AVISAR de que un importe no casa, nunca para calcularlo.
 *
 * Una por tipo y mes: dos remesas de cuotas del mismo mes serían cobrar dos veces.
 *
 * @ORM\Table(name="remittance", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_remittance_kind_period", columns={"kind", "period"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\RemittanceRepository")
 */
class Remittance
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';

    /** Las cuotas mensuales de las cestas: la remesa de todos los meses. */
    public const KIND_BASKET = 'basket';

    /** La cuota de socix (semestral o anual), aparte de la cesta. */
    public const KIND_MEMBERSHIP = 'membership';

    /** Lo que no es ninguna de las dos: derramas, cuotas pendientes… */
    public const KIND_OTHER = 'other';

    public const KIND_LABELS = [
        self::KIND_BASKET => 'Cuotas de cesta',
        self::KIND_MEMBERSHIP => 'Cuota de socix',
        self::KIND_OTHER => 'Otra',
    ];

    public const SOURCE_IMPORT = 'import';
    public const SOURCE_APP = 'app';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $kind;

    /**
     * El mes que se cobra, siempre su día 1.
     *
     * @ORM\Column(type="date_immutable")
     */
    private \DateTimeImmutable $period;

    /**
     * Cómo se llama: «Recibos AGOSTO 2026», «1ª Cuota SEMESTRAL 2025». Es el nombre
     * del fichero que se descarga.
     *
     * @ORM\Column(type="string", length=120)
     */
    private string $label;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $status = self::STATUS_DRAFT;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $source;

    /**
     * El nombre del Excel del que se importó, para saber de dónde salió.
     *
     * @ORM\Column(name="imported_from", type="string", length=255, nullable=true)
     */
    private ?string $importedFrom = null;

    /**
     * @var Collection<int, RemittanceLine>
     *
     * @ORM\OneToMany(targetEntity="RemittanceLine", mappedBy="remittance", cascade={"persist"}, orphanRemoval=true)
     * @ORM\OrderBy({"debtorName" = "ASC", "id" = "ASC"})
     */
    private Collection $lines;

    /**
     * @ORM\Column(name="sent_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * El día de cargo que se pidió al banco al subirla. Con él se vigila que el abono
     * entre en el libro (lo hace `RemittanceChargeWatch`); sin él (las importadas,
     * las de antes) no se vigila. No sale del mes de la remesa: lo que se cobra y
     * cuándo se carga son cosas distintas.
     *
     * @ORM\Column(name="charge_date", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $chargeDate = null;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="sent_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $sentBy = null;

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
     * @param string                 $kind   Uno de los KIND_*.
     * @param \DateTimeInterface     $period Cualquier día del mes que se cobra.
     * @param string                 $source SOURCE_IMPORT o SOURCE_APP.
     */
    public function __construct(string $kind, \DateTimeInterface $period, string $label, string $source, ?User $createdBy)
    {
        if (!isset(self::KIND_LABELS[$kind])) {
            throw new \InvalidArgumentException(sprintf('Tipo de remesa desconocido: "%s".', $kind));
        }
        $this->kind = $kind;
        $this->period = self::firstOfMonth($period);
        $this->label = $label;
        $this->source = $source;
        $this->createdBy = $createdBy;
        $this->lines = new ArrayCollection();
    }

    /**
     * La remesa importada de un Excel ya enviado: entra como enviada, porque ya se
     * cobró, y no se cambia.
     *
     * @param list<RemittanceLine> $lines
     */
    public static function fromImport(string $kind, \DateTimeInterface $period, string $label, string $fileName, array $lines, ?User $by): self
    {
        $remittance = new self($kind, $period, $label, self::SOURCE_IMPORT, $by);
        $remittance->importedFrom = $fileName;
        array_walk($lines, $remittance->addLine(...));
        $remittance->status = self::STATUS_SENT;
        $remittance->sentAt = new \DateTimeImmutable();
        $remittance->sentBy = $by;

        return $remittance;
    }

    /**
     * El borrador del periodo siguiente a partir de una remesa anterior: los mismos
     * recibos, con la referencia de cada adeudo pasada al nuevo mes. Es lo que hoy se
     * hace copiando el Excel del mes pasado.
     *
     * No se copian los recibos que no se repiten: el último de un mandato (FNAL) y
     * los cobros únicos (OOFF). El tipo FRST se copia tal cual porque es lo que hoy
     * se envía todos los meses y Fiare lo acepta.
     */
    public static function nextFrom(self $previous, \DateTimeInterface $period, ?User $by): self
    {
        $period = self::firstOfMonth($period);
        $next = new self($previous->kind, $period, RemittancePeriodText::rewrite($previous->label, $previous->period, $period), self::SOURCE_APP, $by);
        foreach ($previous->lines->filter(static fn (RemittanceLine $l): bool => $l->isRecurring()) as $line) {
            $next->addLine($line->copyFor($previous->period, $period));
        }

        return $next;
    }

    /**
     * Darla por enviada al banco: desde aquí es histórico y no se cambia. Lleva el día
     * de cargo que se pidió, para vigilar que se cobre.
     *
     * @param \DateTimeImmutable $chargeDate El día de cargo pedido al banco.
     *
     * @throws \LogicException Si no es un borrador o aún tiene problemas.
     */
    public function markSent(?User $by, \DateTimeImmutable $chargeDate): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \LogicException('Sólo se envía un borrador.');
        }
        if ($this->sendProblems() !== []) {
            throw new \LogicException('La remesa tiene problemas: ' . implode(' ', $this->sendProblems()));
        }
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->sentBy = $by;
        $this->chargeDate = $chargeDate->setTime(0, 0);
    }

    /**
     * Cambiar el día de cargo de una remesa ya enviada: se rechazó y se volvió a subir
     * con otra fecha, o se le pone a una importada para vigilarla. Sin fecha deja de
     * vigilarse. Es lo único que se cambia de una enviada: no toca ningún recibo.
     *
     * @param \DateTimeImmutable|null $chargeDate El nuevo día de cargo, o null para no vigilarla.
     *
     * @throws \LogicException Si aún es un borrador: la fecha se pone al enviarla.
     */
    public function rescheduleCharge(?\DateTimeImmutable $chargeDate): void
    {
        if (!$this->isSent()) {
            throw new \LogicException('La fecha de cargo se pone al darla por enviada.');
        }
        $this->chargeDate = $chargeDate?->setTime(0, 0);
    }

    /**
     * Lo que impide enviarla: el banco rechaza la remesa entera por un recibo mal
     * hecho, así que se comprueba aquí y no al subirla. La validez del IBAN la mira el
     * validador, que tiene la regla completa.
     *
     * @return list<string>
     */
    public function sendProblems(): array
    {
        if ($this->lines->isEmpty()) {
            return ['No tiene ningún recibo.'];
        }

        $problems = [];
        foreach ($this->lines as $line) {
            foreach ($line->problems() as $problem) {
                $problems[] = sprintf('%s: %s', $line->getDebtorName() !== '' ? $line->getDebtorName() : 'Recibo sin nombre', $problem);
            }
        }
        $references = array_map(static fn (RemittanceLine $l): string => mb_strtoupper(trim($l->getDebitReference())), $this->lines->toArray());
        foreach (array_keys(array_filter(array_count_values($references), static fn (int $n): bool => $n > 1)) as $repeated) {
            $problems[] = sprintf('La referencia de adeudo «%s» está repetida: cada recibo necesita la suya.', $repeated);
        }

        return $problems;
    }

    public function addLine(RemittanceLine $line): self
    {
        if ($this->isSent()) {
            throw new \LogicException('Una remesa enviada no se cambia.');
        }
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setRemittance($this);
        }

        return $this;
    }

    public function removeLine(RemittanceLine $line): self
    {
        if ($this->isSent()) {
            throw new \LogicException('Una remesa enviada no se cambia.');
        }
        $this->lines->removeElement($line);

        return $this;
    }

    /** Suma de los recibos, «7117.00». */
    public function getTotal(): string
    {
        return IssuedInvoice::fromCents(array_sum(array_map(
            static fn (RemittanceLine $l): int => IssuedInvoice::toCents($l->getAmount()),
            $this->lines->toArray(),
        )));
    }

    /**
     * Si se puede tirar: un borrador siempre, y una importada también (se vuelve a
     * subir si se importó mal). Una que se preparó aquí y se envió es el único
     * registro de lo que se cobró, y ésa se queda.
     */
    public function isDeletable(): bool
    {
        return $this->isDraft() || $this->source === self::SOURCE_IMPORT;
    }

    /** El día 1 del mes de una fecha, sin hora. */
    public static function firstOfMonth(\DateTimeInterface $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-01'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getKindLabel(): string
    {
        return self::KIND_LABELS[$this->kind];
    }

    public function isBasketFees(): bool
    {
        return $this->kind === self::KIND_BASKET;
    }

    public function getPeriod(): \DateTimeImmutable
    {
        return $this->period;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isImported(): bool
    {
        return $this->source === self::SOURCE_IMPORT;
    }

    public function getImportedFrom(): ?string
    {
        return $this->importedFrom;
    }

    /** @return Collection<int, RemittanceLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getChargeDate(): ?\DateTimeImmutable
    {
        return $this->chargeDate;
    }

    public function getSentBy(): ?User
    {
        return $this->sentBy;
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
