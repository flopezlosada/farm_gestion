<?php

namespace App\Entity;

use App\Service\Accounting\Norma43\Norma43Movement;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un movimiento de una cuenta de banco tal como lo trae el extracto. Es un HECHO del
 * banco, no un apunte: no entra en ningún saldo ni informe. Sirve para comprobar que
 * el libro lo tiene, ligándolo al apunte que lo anota ({@see linkTo}).
 *
 * Inmutable salvo ese enlace. Ligar no modifica el apunte (la clave está aquí), así que
 * se puede ligar un apunte de un trimestre ya entregado a la gestoría sin reabrirlo.
 *
 * La huella ({@see fingerprint}) hace que volver a subir un extracto, o uno que se
 * solapa con otro, no duplique movimientos: cuenta, fecha, importe, documento,
 * referencias y, para los idénticos del mismo día (dos cargos iguales de una
 * comisión), su ordinal entre ellos.
 *
 * @ORM\Table(name="bank_statement_line", indexes={
 *     @ORM\Index(name="idx_bank_statement_line_account_date", columns={"account_id", "operation_date"})
 * }, uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_bank_statement_line_fingerprint", columns={"fingerprint"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\BankStatementLineRepository")
 */
class BankStatementLine
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="FinancialAccount")
     * @ORM\JoinColumn(name="account_id", nullable=false, onDelete="RESTRICT")
     */
    private FinancialAccount $account;

    /** @ORM\Column(name="operation_date", type="date_immutable") */
    private \DateTimeImmutable $operationDate;

    /** @ORM\Column(name="value_date", type="date_immutable") */
    private \DateTimeImmutable $valueDate;

    /**
     * Con signo, como el apunte: negativo sale, positivo entra.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private string $amount;

    /** @ORM\Column(name="common_concept", type="string", length=2) */
    private string $commonConcept;

    /** @ORM\Column(name="own_concept", type="string", length=3) */
    private string $ownConcept;

    /** @ORM\Column(type="string", length=10) */
    private string $document;

    /** @ORM\Column(type="string", length=12) */
    private string $reference1;

    /** @ORM\Column(type="string", length=16) */
    private string $reference2;

    /**
     * Los textos de los registros 23, uno por línea. Sólo para enseñar: nunca se casa
     * por ellos (llevan nombres y cambian de un banco a otro).
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $details;

    /** @ORM\Column(type="string", length=64) */
    private string $fingerprint;

    /**
     * El apunte que lo anota. SET NULL: si se borra el apunte, el movimiento vuelve a
     * estar sin anotar, que es la verdad.
     *
     * @ORM\OneToOne(targetEntity="AccountEntry")
     * @ORM\JoinColumn(name="entry_id", nullable=true, unique=true, onDelete="SET NULL")
     */
    private ?AccountEntry $entry = null;

    /** @ORM\Column(name="created_at", type="datetime_immutable") */
    private \DateTimeImmutable $createdAt;

    /**
     * @param FinancialAccount $account  La cuenta, ya guardada (su id entra en la huella).
     * @param Norma43Movement  $movement Lo leído del fichero.
     * @param int              $ordinal  1 para el primero de los idénticos del mismo día, 2 para el siguiente…
     */
    public function __construct(FinancialAccount $account, Norma43Movement $movement, int $ordinal)
    {
        $this->account = $account;
        $this->operationDate = $movement->operationDate;
        $this->valueDate = $movement->valueDate;
        $this->amount = IssuedInvoice::fromCents($movement->amountCents);
        $this->commonConcept = $movement->commonConcept;
        $this->ownConcept = $movement->ownConcept;
        $this->document = $movement->document;
        $this->reference1 = $movement->reference1;
        $this->reference2 = $movement->reference2;
        $this->details = $movement->texts !== [] ? implode("\n", $movement->texts) : null;
        $this->fingerprint = self::fingerprint($account, $movement, $ordinal);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * La huella de un movimiento en una cuenta: igual para el mismo movimiento venga en
     * el extracto que venga.
     *
     * @param int $ordinal Posición entre los idénticos del mismo día, desde 1.
     *
     * @throws \LogicException Si la cuenta no está guardada.
     */
    public static function fingerprint(FinancialAccount $account, Norma43Movement $movement, int $ordinal): string
    {
        if ($account->getId() === null) {
            throw new \LogicException('La huella de un movimiento necesita la cuenta ya guardada.');
        }

        return hash('sha256', $account->getId().'|'.$movement->identity().'|'.$ordinal);
    }

    /**
     * Liga el movimiento al apunte que lo anota. Sólo si es de la misma cuenta y el
     * importe es EXACTAMENTE el mismo, con signo: un importe parecido no es el mismo
     * movimiento.
     *
     * @throws \DomainException Si ya estaba ligado o el apunte no puede ser éste.
     */
    public function linkTo(AccountEntry $entry): void
    {
        if ($this->entry !== null) {
            throw new \DomainException('Este movimiento del banco ya está ligado a un apunte.');
        }
        if ($entry->getAccount() !== $this->account) {
            throw new \DomainException('El apunte es de otra cuenta.');
        }
        if (IssuedInvoice::toCents($entry->getAmount()) !== $this->getAmountCents()) {
            throw new \DomainException('El apunte no tiene el mismo importe que el movimiento del banco.');
        }
        $this->entry = $entry;
    }

    /**
     * Si el movimiento cuenta para el libro: desde la apertura de su cuenta. Lo anterior
     * ya está dentro del saldo de apertura y no tiene apunte que buscarle.
     */
    public function isWithinBook(): bool
    {
        return $this->account->isOpenOn($this->operationDate);
    }

    /**
     * Si su concepto común suele juntar varios apuntes en un cargo (una remesa, la
     * tarjeta, devoluciones): sin apunte del mismo importe, se revisa a mano.
     */
    public function isGrouped(): bool
    {
        return isset(Norma43Movement::GROUPED_CONCEPTS[$this->commonConcept]);
    }

    /** Qué tipo de cargo agrupado es, o null si no lo es. */
    public function getGroupedLabel(): ?string
    {
        return Norma43Movement::GROUPED_CONCEPTS[$this->commonConcept] ?? null;
    }

    /** El texto que se propone como concepto al anotarlo: el primero del banco, o los códigos si no trae. */
    public function getSuggestedConcept(): string
    {
        $first = $this->details !== null ? explode("\n", $this->details)[0] : '';

        return mb_substr($first !== '' ? $first : sprintf('Movimiento del banco (concepto %s-%s)', $this->commonConcept, $this->ownConcept), 0, 255);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): FinancialAccount
    {
        return $this->account;
    }

    public function getOperationDate(): \DateTimeImmutable
    {
        return $this->operationDate;
    }

    public function getValueDate(): \DateTimeImmutable
    {
        return $this->valueDate;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getAmountCents(): int
    {
        return IssuedInvoice::toCents($this->amount);
    }

    public function getCommonConcept(): string
    {
        return $this->commonConcept;
    }

    public function getOwnConcept(): string
    {
        return $this->ownConcept;
    }

    public function getDocument(): string
    {
        return $this->document;
    }

    public function getReference1(): string
    {
        return $this->reference1;
    }

    public function getReference2(): string
    {
        return $this->reference2;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getFingerprintValue(): string
    {
        return $this->fingerprint;
    }

    public function getEntry(): ?AccountEntry
    {
        return $this->entry;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
