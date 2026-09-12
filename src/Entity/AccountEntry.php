<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un movimiento de dinero en una cuenta de la asociación. Es la unidad del libro y la
 * ÚNICA cosa que se teclea: el saldo de cada cuenta, el resumen del mes, el total del
 * año y el seguimiento del presupuesto salen todos de sumar apuntes.
 *
 * El importe lleva SIGNO —positivo entra, negativo sale— en vez de dos columnas
 * separadas como el Excel. Así el saldo es una suma, no puede existir un apunte que
 * sea ingreso y gasto a la vez, y una devolución es simplemente el mismo concepto con
 * el signo cambiado. El formulario sigue enseñando dos casillas, «entra» y «sale»,
 * porque es como se trabaja con un extracto delante.
 *
 * @ORM\Table(name="account_entry", indexes={
 *     @ORM\Index(name="idx_account_entry_date", columns={"entry_date"}),
 *     @ORM\Index(name="idx_account_entry_account_date", columns={"account_id", "entry_date"}),
 *     @ORM\Index(name="idx_account_entry_category", columns={"category_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\AccountEntryRepository")
 */
class AccountEntry
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Fecha del movimiento en la cuenta, la del extracto. No es la fecha de la factura
     * ni la de cuando se anotó: es la que determina en qué mes cuenta.
     * @ORM\Column(name="entry_date", type="date")
     */
    #[Assert\NotNull]
    private ?\DateTimeInterface $date = null;

    /**
     * RESTRICT: una cuenta con apuntes no se borra, se desactiva. Borrarla dejaría los
     * apuntes huérfanos y el saldo histórico sin explicación.
     *
     * @ORM\ManyToOne(targetEntity="FinancialAccount")
     * @ORM\JoinColumn(name="account_id", nullable=false, onDelete="RESTRICT")
     */
    #[Assert\NotNull]
    private ?FinancialAccount $account = null;

    /**
     * Obligatoria. En el Excel se puede dejar en blanco, y de hecho hay 2.413,82 € de
     * 2026 sin clasificar: están en el saldo pero no salen en ningún informe, así que
     * el dinero aparece y desaparece sin que nada lo explique. Aquí no se puede.
     *
     * @ORM\ManyToOne(targetEntity="BudgetCategory")
     * @ORM\JoinColumn(name="category_id", nullable=false, onDelete="RESTRICT")
     */
    #[Assert\NotNull]
    private ?BudgetCategory $category = null;

    /**
     * El texto del extracto o la explicación de a qué corresponde.
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $concept = '';

    /**
     * Texto libre, no una ficha de proveedor: aquí interesa poder buscar «a quién le
     * pagamos esto», no llevar un fichero de proveedores. Si algún día hace falta, se
     * normaliza entonces.
     * @ORM\Column(name="provider_name", type="string", length=150, nullable=true)
     */
    #[Assert\Length(max: 150)]
    private ?string $providerName = null;

    /**
     * @ORM\Column(name="invoice_number", type="string", length=50, nullable=true)
     */
    #[Assert\Length(max: 50)]
    private ?string $invoiceNumber = null;

    /**
     * Positivo si el dinero entra en la cuenta, negativo si sale. Nunca cero: un
     * apunte de cero no es un movimiento.
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    #[Assert\NotNull]
    #[Assert\NotEqualTo(value: 0, message: 'El importe no puede ser cero.')]
    private string $amount = '0';

    /**
     * La otra mitad de un traspaso: el apunte que sale de una cuenta y el que entra en
     * la otra se apuntan mutuamente. Sin este enlace un traspaso se puede quedar a
     * medias y el saldo de una cuenta queda mal sin que nada chille — en el libro de
     * 2026 los traspasos descuadran en 615 €, que es exactamente eso.
     *
     * @ORM\OneToOne(targetEntity="AccountEntry")
     * @ORM\JoinColumn(name="transfer_peer_id", nullable=true, unique=true, onDelete="SET NULL")
     */
    private ?AccountEntry $transferPeer = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * Quién lo anotó. SET NULL: si la cuenta de esa persona se borra, el apunte se
     * queda (el dinero se movió igualmente).
     *
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
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated_at", type="datetime")
     */
    private ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(?\DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getAccount(): ?FinancialAccount
    {
        return $this->account;
    }

    public function setAccount(?FinancialAccount $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getCategory(): ?BudgetCategory
    {
        return $this->category;
    }

    public function setCategory(?BudgetCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getConcept(): string
    {
        return $this->concept;
    }

    public function setConcept(string $concept): self
    {
        $this->concept = $concept;

        return $this;
    }

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    public function setProviderName(?string $providerName): self
    {
        $this->providerName = $providerName;

        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): self
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    /** Si el dinero entra en la cuenta (frente a salir de ella). */
    public function isIncoming(): bool
    {
        return (float) $this->amount > 0;
    }

    /** Importe sin signo, para pintarlo en la columna que le toca. */
    public function getAbsoluteAmount(): string
    {
        return ltrim($this->amount, '-');
    }

    public function getTransferPeer(): ?AccountEntry
    {
        return $this->transferPeer;
    }

    /**
     * Enlaza las dos mitades de un traspaso en los dos sentidos. Siempre por aquí:
     * enlazar sólo un lado deja el otro suelto, que es el fallo que este campo existe
     * para impedir.
     */
    public function pairWith(?AccountEntry $peer): self
    {
        $this->transferPeer = $peer;
        if ($peer !== null && $peer->getTransferPeer() !== $this) {
            $peer->pairWith($this);
        }

        return $this;
    }

    /** Si el apunte es una de las dos mitades de un traspaso entre cuentas propias. */
    public function isTransfer(): bool
    {
        return $this->category !== null && $this->category->isTransfer();
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
