<?php

namespace App\Entity;

use App\Service\Accounting\Norma43\Norma43Account;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un extracto de una cuenta de banco tal como lo dio el banco: qué días cubre y con qué
 * saldo empezó y acabó. Es la referencia del cuadre: el libro, en esas fechas, tiene
 * que dar los mismos saldos.
 *
 * Los movimientos NO cuelgan de aquí: son de la cuenta ({@see BankStatementLine}), para
 * que dos extractos que se solapan no los cuenten dos veces. Los de un extracto son los
 * de su cuenta entre sus dos fechas.
 *
 * Inmutable: si el banco lo da mal, se sube el bueno.
 *
 * @ORM\Table(name="bank_statement", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_bank_statement_period", columns={"account_id", "date_from", "date_to"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\BankStatementRepository")
 */
class BankStatement
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * RESTRICT: una cuenta con extractos no se borra; se retira, como con apuntes.
     *
     * @ORM\ManyToOne(targetEntity="FinancialAccount")
     * @ORM\JoinColumn(name="account_id", nullable=false, onDelete="RESTRICT")
     */
    private FinancialAccount $account;

    /** @ORM\Column(name="date_from", type="date_immutable") */
    private \DateTimeImmutable $from;

    /** @ORM\Column(name="date_to", type="date_immutable") */
    private \DateTimeImmutable $to;

    /**
     * Saldo al empezar el primer día, con signo.
     *
     * @ORM\Column(name="opening_balance", type="decimal", precision=10, scale=2)
     */
    private string $openingBalance;

    /**
     * Saldo al acabar el último día, con signo.
     *
     * @ORM\Column(name="closing_balance", type="decimal", precision=10, scale=2)
     */
    private string $closingBalance;

    /** @ORM\Column(name="file_name", type="string", length=255) */
    private string $fileName;

    /** @ORM\Column(name="imported_at", type="datetime_immutable") */
    private \DateTimeImmutable $importedAt;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="imported_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $importedBy;

    /**
     * Un extracto a partir de la cuenta leída del fichero.
     *
     * @param FinancialAccount $account  La cuenta del libro a la que corresponde.
     * @param Norma43Account   $read     Lo leído del fichero.
     * @param string           $fileName Nombre del fichero, para reconocerlo.
     * @param User|null        $by       Quién lo subió.
     */
    public function __construct(FinancialAccount $account, Norma43Account $read, string $fileName, ?User $by)
    {
        $this->account = $account;
        $this->from = $read->from;
        $this->to = $read->to;
        $this->openingBalance = IssuedInvoice::fromCents($read->openingCents);
        $this->closingBalance = IssuedInvoice::fromCents($read->closingCents);
        $this->fileName = mb_substr($fileName, 0, 255);
        $this->importedAt = new \DateTimeImmutable();
        $this->importedBy = $by;
    }

    /** Si dice lo mismo que lo leído de un fichero: mismos saldos, mismas fechas. */
    public function agreesWith(Norma43Account $read): bool
    {
        return $this->from == $read->from
            && $this->to == $read->to
            && IssuedInvoice::toCents($this->openingBalance) === $read->openingCents
            && IssuedInvoice::toCents($this->closingBalance) === $read->closingCents;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): FinancialAccount
    {
        return $this->account;
    }

    public function getFrom(): \DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): \DateTimeImmutable
    {
        return $this->to;
    }

    public function getOpeningBalance(): string
    {
        return $this->openingBalance;
    }

    public function getClosingBalance(): string
    {
        return $this->closingBalance;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function getImportedBy(): ?User
    {
        return $this->importedBy;
    }
}
