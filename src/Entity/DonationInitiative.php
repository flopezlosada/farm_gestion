<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una iniciativa de la asociación a la que lxs socixs pueden destinar lo que pagan de
 * más: «Cestas para la RSP», por ejemplo, que paga cestas a quien no puede. Cada
 * socix se acoge con la cantidad que quiere, como un ajuste positivo de su cuota.
 *
 * No se borra si ya ha recibido algo: se cierra, y deja de ofrecerse.
 *
 * @ORM\Table(name="donation_initiative")
 * @ORM\Entity
 */
#[UniqueEntity(fields: ['name'], message: 'Ya hay una iniciativa con ese nombre.')]
class DonationInitiative
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=120, unique=true)
     */
    #[Assert\NotBlank(message: 'Ponle un nombre.')]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /**
     * Para qué es: lo que se le cuenta a quien se acoge.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * Si se le pueden destinar aportaciones nuevas. Las que ya tiene siguen.
     *
     * @ORM\Column(name="is_open", type="boolean")
     */
    private bool $open = true;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    public function __toString(): string
    {
        return $this->name;
    }

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $description = trim((string) $description);
        $this->description = $description !== '' ? $description : null;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function setOpen(bool $open): self
    {
        $this->open = $open;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
