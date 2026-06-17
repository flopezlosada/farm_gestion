<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Procedencia de un {@see Helper}: de dónde nos llega esa persona (WWOOF,
 * Workaway, HelpX, prácticas universitarias, boca a boca...).
 *
 * Es un CATÁLOGO como dato, no una jerarquía de clases: añadir un origen nuevo
 * es una fila, no una subclase ni un deploy. Por eso el "tipo de voluntario de
 * acogida" se modela aquí y no con herencia (ver design del módulo albergue).
 *
 * @ORM\Table(name="helper_source")
 * @ORM\Entity(repositoryClass="App\Repository\HelperSourceRepository")
 */
class HelperSource
{
    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @ORM\Column(name="name", type="string", length=120)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private $name;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;

    /**
     * @ORM\OneToMany(targetEntity="Helper", mappedBy="source")
     */
    private $helpers;

    /**
     * Inicializa la colección de voluntarios de esta procedencia.
     */
    public function __construct()
    {
        $this->helpers = new ArrayCollection();
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    /**
     * @return \DateTime|null
     */
    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    /**
     * @return Collection<int, Helper>
     */
    public function getHelpers(): Collection
    {
        return $this->helpers;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->name;
    }
}
