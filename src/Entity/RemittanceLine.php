<?php

namespace App\Entity;

use App\Service\Accounting\Remittance\RemittancePeriodText;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un recibo de una remesa: a quién se le carga, en qué cuenta, con qué mandato y
 * cuánto. Son las columnas del cuaderno 19 de Fiare, tal cual.
 *
 * Guarda una COPIA de los datos del deudor (nombre, IBAN, mandato) en vez de leerlos
 * de la ficha del socix: el recibo enviado es lo que se envió, aunque la ficha cambie
 * después. Y porque muchas veces quien paga no es quien tiene la cesta, o no está en
 * la base: el enlace al socix ayuda a revisar, pero no es imprescindible.
 *
 * @ORM\Table(name="remittance_line", indexes={
 *     @ORM\Index(name="idx_remittance_line_mandate", columns={"mandate_reference"})
 * })
 * @ORM\Entity
 */
class RemittanceLine
{
    /** Primer adeudo de un mandato. */
    public const SEQUENCE_FIRST = 'FRST';

    /** Los siguientes. */
    public const SEQUENCE_RECURRING = 'RCUR';

    /** El último antes de cancelar el mandato. */
    public const SEQUENCE_FINAL = 'FNAL';

    /** Un cobro único, sin serie. */
    public const SEQUENCE_ONE_OFF = 'OOFF';

    public const SEQUENCE_LABELS = [
        self::SEQUENCE_FIRST => 'FRST · primero',
        self::SEQUENCE_RECURRING => 'RCUR · recurrente',
        self::SEQUENCE_FINAL => 'FNAL · último',
        self::SEQUENCE_ONE_OFF => 'OOFF · único',
    ];

    /** Lo más largo que admite el banco en el nombre del deudor. */
    public const MAX_NAME = 70;

    /** Lo más largo que admite el banco en las referencias de mandato y adeudo. */
    public const MAX_REFERENCE = 35;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Remittance", inversedBy="lines")
     * @ORM\JoinColumn(name="remittance_id", nullable=false, onDelete="CASCADE")
     */
    private ?Remittance $remittance = null;

    /**
     * El socix al que corresponde, si se sabe. SET NULL: el recibo sigue siendo lo
     * que se cobró aunque la ficha desaparezca.
     *
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", nullable=true, onDelete="SET NULL")
     */
    private ?Partner $partner = null;

    /**
     * @ORM\Column(name="debtor_name", type="string", length=70)
     */
    #[Assert\NotBlank(message: 'Falta el nombre de quien paga.')]
    #[Assert\Length(max: self::MAX_NAME)]
    private string $debtorName = '';

    /**
     * La cuenta en que se carga, sin espacios.
     *
     * @ORM\Column(type="string", length=34)
     */
    #[Assert\NotBlank(message: 'Falta la cuenta.')]
    #[Assert\Iban(message: 'Esa cuenta no es un IBAN válido.')]
    private string $iban = '';

    /**
     * La referencia del mandato que firmó quien paga («CUOTA MENSUAL 200»). No se
     * reutiliza nunca, ni tras una baja: lo dice el banco.
     *
     * @ORM\Column(name="mandate_reference", type="string", length=35)
     */
    #[Assert\NotBlank(message: 'Falta la referencia del mandato.')]
    #[Assert\Length(max: self::MAX_REFERENCE)]
    private string $mandateReference = '';

    /**
     * @ORM\Column(name="mandate_signed_on", type="date_immutable", nullable=true)
     */
    #[Assert\NotNull(message: 'Falta la fecha en que se firmó el mandato.')]
    private ?\DateTimeImmutable $mandateSignedOn = null;

    /**
     * Identifica este recibo ante el banco; distinta en cada uno.
     *
     * @ORM\Column(name="debit_reference", type="string", length=35)
     */
    #[Assert\NotBlank(message: 'Falta la referencia del adeudo.')]
    #[Assert\Length(max: self::MAX_REFERENCE)]
    private string $debitReference = '';

    /**
     * @ORM\Column(name="sequence_type", type="string", length=4)
     */
    #[Assert\Choice(choices: [self::SEQUENCE_FIRST, self::SEQUENCE_RECURRING, self::SEQUENCE_FINAL, self::SEQUENCE_ONE_OFF])]
    private string $sequenceType = self::SEQUENCE_FIRST;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    #[Assert\Positive(message: 'El importe tiene que ser mayor que cero.')]
    private string $amount = '0';

    /**
     * El recibo de una persona con los datos de cobro de su ficha.
     *
     * Tipo de adeudo FRST en todos, como se envía hoy y Fiare acepta (los 120 recibos
     * de agosto de 2026 lo llevan). La norma SEPA pediría RCUR desde el segundo cobro
     * de un mandato; no se cambia sin confirmarlo con el banco, porque un tipo que no
     * espera le haría rechazar la remesa entera.
     *
     * @param Partner $payer     Quien paga; con mandato completo ({@see Partner::hasDirectDebit}).
     * @param string  $amount    Lo que se le carga.
     * @param string  $reference Referencia del adeudo, que distingue este cobro de los demás del mandato.
     */
    public static function forPayer(Partner $payer, string $amount, string $reference): self
    {
        return (new self())
            ->setPartner($payer)
            ->setDebtorName(mb_substr(mb_strtoupper($payer->getLegalName()), 0, self::MAX_NAME))
            ->setIban($payer->getIban())
            ->setMandateReference((string) $payer->getMandateReference())
            ->setMandateSignedOn($payer->getMandateSignedOn())
            ->setDebitReference(mb_substr($reference, 0, self::MAX_REFERENCE))
            ->setSequenceType(self::SEQUENCE_FIRST)
            ->setAmount($amount);
    }

    /**
     * El mismo recibo para el periodo siguiente: mismos datos, con la referencia del
     * adeudo pasada al mes nuevo.
     */
    public function copyFor(\DateTimeInterface $from, \DateTimeInterface $to): self
    {
        $copy = clone $this;
        $copy->id = null;
        $copy->remittance = null;
        $copy->debitReference = RemittancePeriodText::rewrite($this->debitReference, $from, $to);

        return $copy;
    }

    /**
     * Lo que le falta para poder enviarse, salvo la validez del IBAN, que comprueba el
     * validador (necesita la regla de cada país).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $tooLong = static fn (string $v, int $max): bool => mb_strlen($v) > $max;

        return array_values(array_filter([
            trim($this->debtorName) === '' ? 'falta el nombre.' : null,
            $tooLong($this->debtorName, self::MAX_NAME) ? sprintf('el nombre pasa de %d letras.', self::MAX_NAME) : null,
            $this->iban === '' ? 'falta la cuenta.' : null,
            trim($this->mandateReference) === '' ? 'falta la referencia del mandato.' : null,
            $tooLong($this->mandateReference, self::MAX_REFERENCE) ? sprintf('la referencia del mandato pasa de %d letras.', self::MAX_REFERENCE) : null,
            $this->mandateSignedOn === null ? 'falta la fecha de firma del mandato.' : null,
            trim($this->debitReference) === '' ? 'falta la referencia del adeudo.' : null,
            $tooLong($this->debitReference, self::MAX_REFERENCE) ? sprintf('la referencia del adeudo pasa de %d letras.', self::MAX_REFERENCE) : null,
            !isset(self::SEQUENCE_LABELS[$this->sequenceType]) ? sprintf('el tipo de adeudo «%s» no existe.', $this->sequenceType) : null,
            IssuedInvoice::toCents($this->amount) <= 0 ? 'el importe tiene que ser mayor que cero.' : null,
        ]));
    }

    /** Si el recibo se repite el mes siguiente: no lo hacen el último de un mandato ni un cobro único. */
    public function isRecurring(): bool
    {
        return !\in_array($this->sequenceType, [self::SEQUENCE_FINAL, self::SEQUENCE_ONE_OFF], true);
    }

    /** «ES12 … 3456»: para listados, donde la cuenta entera no hace falta. */
    public function getMaskedIban(): string
    {
        return self::maskIban($this->iban);
    }

    /** Cualquier cuenta como «ES12 … 3456». */
    public static function maskIban(string $iban): string
    {
        return \strlen($iban) < 8 ? $iban : substr($iban, 0, 4) . ' … ' . substr($iban, -4);
    }

    /**
     * Un IBAN tal como se guarda: mayúsculas, sólo letras y cifras. Un IBAN no lleva
     * signos, y los Excel hechos a mano traen espacios, guiones o un punto al final.
     */
    public static function normalizeIban(?string $iban): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $iban));
    }

    /** Un recibo de una remesa ya enviada es lo que se cobró: no se cambia. */
    private function assertEditable(): void
    {
        if ($this->remittance !== null && $this->remittance->isSent()) {
            throw new \LogicException('Una remesa enviada no se cambia.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRemittance(): ?Remittance
    {
        return $this->remittance;
    }

    public function setRemittance(?Remittance $remittance): self
    {
        $this->remittance = $remittance;

        return $this;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): self
    {
        $this->assertEditable();
        $this->partner = $partner;

        return $this;
    }

    public function getDebtorName(): string
    {
        return $this->debtorName;
    }

    public function setDebtorName(?string $debtorName): self
    {
        $this->assertEditable();
        $this->debtorName = trim((string) $debtorName);

        return $this;
    }

    public function getIban(): string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): self
    {
        $this->assertEditable();
        $this->iban = self::normalizeIban($iban);

        return $this;
    }

    public function getMandateReference(): string
    {
        return $this->mandateReference;
    }

    public function setMandateReference(?string $mandateReference): self
    {
        $this->assertEditable();
        $this->mandateReference = trim((string) $mandateReference);

        return $this;
    }

    public function getMandateSignedOn(): ?\DateTimeImmutable
    {
        return $this->mandateSignedOn;
    }

    public function setMandateSignedOn(?\DateTimeInterface $mandateSignedOn): self
    {
        $this->assertEditable();
        $this->mandateSignedOn = $mandateSignedOn !== null ? \DateTimeImmutable::createFromInterface($mandateSignedOn)->setTime(0, 0) : null;

        return $this;
    }

    public function getDebitReference(): string
    {
        return $this->debitReference;
    }

    public function setDebitReference(?string $debitReference): self
    {
        $this->assertEditable();
        $this->debitReference = trim((string) $debitReference);

        return $this;
    }

    public function getSequenceType(): string
    {
        return $this->sequenceType;
    }

    public function setSequenceType(?string $sequenceType): self
    {
        $this->assertEditable();
        $this->sequenceType = strtoupper(trim((string) $sequenceType));

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): self
    {
        $this->assertEditable();
        $this->amount = (string) $amount;

        return $this;
    }
}
