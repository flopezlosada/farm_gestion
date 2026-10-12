<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A quién factura la asociación: ayuntamientos, fundaciones, institutos,
 * cooperativas. Se da de alta una vez y se elige al hacer cada factura, en vez de
 * volver a teclear (y a equivocar) el nombre y el CIF cada vez.
 *
 * No es la tabla de proveedores aunque se parezca: quien nos vende y a quien
 * vendemos son papeles distintos, y una misma entidad puede tener los dos sin que
 * mezclarlos ayude a nadie.
 *
 * Una factura emitida copia estos datos al emitirse ({@see IssuedInvoice::issue()}):
 * cambiar aquí la dirección no reescribe las facturas que ya salieron.
 *
 * @ORM\Table(name="customer")
 * @ORM\Entity(repositoryClass="App\Repository\CustomerRepository")
 */
#[UniqueEntity(fields: ['taxId'], message: 'Ya hay un cliente con ese CIF.')]
class Customer
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Razón social tal como va en la factura. Admite saltos de línea: hay clientes
     * que piden una segunda línea («Fundación Daniel y Nina Carasso (Afiliada…)»).
     *
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank(message: 'Falta el nombre.')]
    #[Assert\Length(max: 255)]
    private string $name = '';

    /**
     * CIF o NIF. Opcional al dar de alta, obligatorio para emitirle una factura
     * completa: sin él no se puede emitir.
     *
     * @ORM\Column(name="tax_id", type="string", length=20, nullable=true, unique=true)
     */
    #[Assert\Length(max: 20)]
    private ?string $taxId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $address = null;

    /**
     * Texto: un código postal que empieza por cero lo perdería como número.
     *
     * @ORM\Column(name="postal_code", type="string", length=10, nullable=true)
     */
    #[Assert\Length(max: 10)]
    private ?string $postalCode = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    #[Assert\Length(max: 100)]
    private ?string $town = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    #[Assert\Length(max: 100)]
    private ?string $province = null;

    /**
     * Lo que este cliente exige en cada factura suya: los códigos DIR3 de una
     * administración pública, un número de expediente… Se imprime en todas sus
     * facturas sin tener que acordarse.
     *
     * @ORM\Column(name="invoice_note", type="text", nullable=true)
     */
    #[Assert\Length(max: 1000)]
    private ?string $invoiceNote = null;

    /**
     * Correo de facturación, para tenerlo a mano.
     *
     * @ORM\Column(type="string", length=180, nullable=true)
     */
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    /**
     * Se guarda normalizado, igual que el de un proveedor: es como se compara, y
     * «B-12.345.678» y «b12345678» son el mismo CIF.
     */
    public function setTaxId(?string $taxId): self
    {
        $this->taxId = Provider::normalizeTaxId($taxId);

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = self::blankToNull($address);

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): self
    {
        $this->postalCode = self::blankToNull($postalCode);

        return $this;
    }

    public function getTown(): ?string
    {
        return $this->town;
    }

    public function setTown(?string $town): self
    {
        $this->town = self::blankToNull($town);

        return $this;
    }

    public function getProvince(): ?string
    {
        return $this->province;
    }

    public function setProvince(?string $province): self
    {
        $this->province = self::blankToNull($province);

        return $this;
    }

    public function getInvoiceNote(): ?string
    {
        return $this->invoiceNote;
    }

    public function setInvoiceNote(?string $invoiceNote): self
    {
        $this->invoiceNote = self::blankToNull($invoiceNote);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = self::blankToNull($email);

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

    public function __toString(): string
    {
        return $this->name;
    }

    /** Un campo de texto vacío es «no lo sé», no una cadena vacía. */
    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
