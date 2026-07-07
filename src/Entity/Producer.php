<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Productor del GRUPO DE CONSUMO: quien suministra los productos de las rondas de
 * pedido colectivo. Es una entidad PERSISTENTE y reutilizable — su catálogo
 * ({@see ConsumerGroupProduct}) sobrevive entre rondas, no se reteclea cada vez.
 *
 * NO se modela como {@see Partner}: un productor lo más habitual es que NO consuma
 * cesta, y algunos ni son socios. Cuando el productor autogestiona sus pedidos, se
 * le provisiona un {@see User} con ROLE_PRODUCER (faceta propia, mismo patrón que
 * Worker/Partner en {@see User::getRoles()}); ese User apunta aquí vía su OneToOne.
 *
 * Modo de gestión (lo decide admin, {@see $selfManaged}):
 *   - autogestión (true):  el productor lleva sus propias rondas desde su panel.
 *   - vía comisión (false): sin cuenta; la comisión del grupo de consumo gestiona
 *     sus rondas en /gestion/consumer-group.
 *
 * @ORM\Table(name="consumer_group_producer")
 * @ORM\Entity(repositoryClass="App\Repository\ProducerRepository")
 */
class Producer
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=180)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $name = '';

    /**
     * Persona de contacto en el productor (opcional).
     * @ORM\Column(type="string", length=180, nullable=true)
     */
    #[Assert\Length(max: 180)]
    private ?string $contactName = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    private ?string $email = null;

    /**
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    #[Assert\Length(max: 30)]
    private ?string $phone = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $web = null;

    /**
     * Notas internas de la comisión sobre el productor (opcional).
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * Nota de pedido mínimo por defecto (informativa), que precarga la condición de
     * mínimo al abrir una ronda de este productor. No se calcula (ver diseño).
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $minimumNote = null;

    /**
     * ¿El productor autogestiona sus rondas (true) o las lleva la comisión (false)?
     * Lo decide admin. Si autogestiona, se le provisiona un User con ROLE_PRODUCER.
     * @ORM\Column(type="boolean")
     */
    private bool $selfManaged = false;

    /**
     * Productor de baja: no aparece para abrir rondas nuevas, pero se conserva con
     * su histórico.
     * @ORM\Column(type="boolean")
     */
    private bool $active = true;

    /**
     * Catálogo de productos del productor (persistente, reutilizable entre rondas).
     * @ORM\OneToMany(targetEntity="ConsumerGroupProduct", mappedBy="producer", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"sortOrder": "ASC", "id": "ASC"})
     * @var Collection<int, ConsumerGroupProduct>
     */
    private Collection $products;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $created = null;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $updated = null;

    public function __construct()
    {
        $this->products = new ArrayCollection();
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

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): self
    {
        $this->contactName = $contactName;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getWeb(): ?string
    {
        return $this->web;
    }

    public function setWeb(?string $web): self
    {
        $this->web = $web;
        return $this;
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

    public function getMinimumNote(): ?string
    {
        return $this->minimumNote;
    }

    public function setMinimumNote(?string $minimumNote): self
    {
        $this->minimumNote = $minimumNote;
        return $this;
    }

    public function isSelfManaged(): bool
    {
        return $this->selfManaged;
    }

    public function setSelfManaged(bool $selfManaged): self
    {
        $this->selfManaged = $selfManaged;
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

    /**
     * @return Collection<int, ConsumerGroupProduct>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    /**
     * Solo los productos activos del catálogo, para ofrecerlos al abrir una ronda.
     *
     * @return ConsumerGroupProduct[]
     */
    public function getActiveProducts(): array
    {
        return $this->products->filter(static fn (ConsumerGroupProduct $p): bool => $p->isActive())->getValues();
    }

    public function addProduct(ConsumerGroupProduct $product): self
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setProducer($this);
        }
        return $this;
    }

    public function removeProduct(ConsumerGroupProduct $product): self
    {
        if ($this->products->removeElement($product)) {
            if ($product->getProducer() === $this) {
                $product->setProducer(null);
            }
        }
        return $this;
    }

    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
