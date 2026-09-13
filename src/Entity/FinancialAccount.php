<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cuenta donde vive el dinero de la asociación: una cuenta bancaria (Fiare, La Caixa,
 * PayPal) o una caja de efectivo (la del local, la de la huerta).
 *
 * El saldo NO se guarda: es {@see getOpeningBalance} más la suma de los apuntes
 * posteriores a {@see getOpeningDate}. Guardarlo obligaría a mantenerlo al día en cada
 * alta, baja y corrección de un apunte, y cualquier fallo dejaría un saldo que miente
 * sin que nada lo delate.
 *
 * @ORM\Table(name="financial_account")
 * @ORM\Entity(repositoryClass="App\Repository\FinancialAccountRepository")
 */
#[UniqueEntity(fields: ['name'], message: 'Ya hay una cuenta con ese nombre.')]
class FinancialAccount
{
    /** Cuenta bancaria: los apuntes salen del extracto. */
    public const KIND_BANK = 1;

    /** Caja de efectivo: los apuntes se anotan a mano. */
    public const KIND_CASH = 2;

    /** Etiquetas de los tipos, para formularios y listados. */
    public const KIND_LABELS = [
        self::KIND_BANK => 'Cuenta bancaria',
        self::KIND_CASH => 'Caja de efectivo',
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    /**
     * @ORM\Column(type="smallint")
     */
    #[Assert\Choice(callback: 'kindValues')]
    private int $kind = self::KIND_BANK;

    /**
     * Sólo las bancarias. Se guarda sin espacios y en mayúsculas.
     * @ORM\Column(type="string", length=34, nullable=true)
     */
    private ?string $iban = null;

    /**
     * Saldo con el que la cuenta entra en la aplicación. Es el punto de partida del
     * cálculo del saldo, así que se fija UNA vez, al migrar desde el Excel, y no se
     * vuelve a tocar: corregirlo después movería todos los saldos históricos.
     * @ORM\Column(name="opening_balance", type="decimal", precision=10, scale=2)
     */
    #[Assert\NotNull]
    private string $openingBalance = '0';

    /**
     * Fecha a la que corresponde {@see getOpeningBalance}. Los apuntes anteriores a
     * esta fecha no cuentan para el saldo (son histórico previo a la migración).
     * @ORM\Column(name="opening_date", type="date")
     */
    #[Assert\NotNull]
    private ?\DateTimeInterface $openingDate = null;

    /**
     * Una cuenta cerrada deja de ofrecerse al anotar, pero conserva sus apuntes.
     * @ORM\Column(name="is_active", type="boolean")
     */
    private bool $active = true;

    /**
     * Orden de presentación en listados y resúmenes.
     * @ORM\Column(name="sort_order", type="smallint")
     */
    private int $sortOrder = 0;

    /** Valores válidos de {@see getKind}, para la validación del formulario. */
    public static function kindValues(): array
    {
        return array_keys(self::KIND_LABELS);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getKind(): int
    {
        return $this->kind;
    }

    public function setKind(int $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    /** Etiqueta legible del tipo de cuenta. */
    public function getKindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? '';
    }

    /** Si el dinero está en un banco (frente a una caja de efectivo). */
    public function isBank(): bool
    {
        return $this->kind === self::KIND_BANK;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    /** Normaliza el IBAN al guardarlo: sin espacios y en mayúsculas. */
    public function setIban(?string $iban): self
    {
        $this->iban = $iban !== null ? strtoupper(preg_replace('/\s+/', '', $iban)) : null;

        return $this;
    }

    public function getOpeningBalance(): string
    {
        return $this->openingBalance;
    }

    public function setOpeningBalance(string $openingBalance): self
    {
        $this->openingBalance = $openingBalance;

        return $this;
    }

    public function getOpeningDate(): ?\DateTimeInterface
    {
        return $this->openingDate;
    }

    public function setOpeningDate(?\DateTimeInterface $openingDate): self
    {
        $this->openingDate = $openingDate;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
