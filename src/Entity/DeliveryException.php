<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Excepción al calendario semanal de reparto. El reparto normal cae en
 * viernes; estas filas marcan que un viernes concreto no hay reparto
 * (shifted_date = null) o que el reparto se traslada a otro día
 * (shifted_date = fecha objetivo, típicamente jueves o miércoles previos
 * si el viernes es festivo).
 *
 * La ausencia de un registro para un viernes equivale a "reparto normal
 * ese viernes". No es necesario crear DeliveryException para cada semana.
 *
 * @ORM\Table(name="delivery_exception")
 * @ORM\Entity(repositoryClass="App\Repository\DeliveryExceptionRepository")
 */
class DeliveryException
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Viernes original afectado. Clave de negocio única.
     * @ORM\Column(type="date", unique=true)
     */
    #[Assert\NotNull]
    private ?\DateTimeInterface $fridayDate = null;

    /**
     * Día al que se traslada el reparto. Si es null, no hay reparto esa semana.
     * Si es distinto del viernes original, el reparto cae en esa fecha.
     * @ORM\Column(type="date", nullable=true)
     */
    private ?\DateTimeInterface $shiftedDate = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFridayDate(): ?\DateTimeInterface
    {
        return $this->fridayDate;
    }

    public function setFridayDate(\DateTimeInterface $fridayDate): self
    {
        $this->fridayDate = $fridayDate;
        return $this;
    }

    public function getShiftedDate(): ?\DateTimeInterface
    {
        return $this->shiftedDate;
    }

    public function setShiftedDate(?\DateTimeInterface $shiftedDate): self
    {
        $this->shiftedDate = $shiftedDate;
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

    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    public function isCancelled(): bool
    {
        return $this->shiftedDate === null;
    }

    public function isShifted(): bool
    {
        return $this->shiftedDate !== null
            && $this->fridayDate !== null
            && $this->shiftedDate->format('Y-m-d') !== $this->fridayDate->format('Y-m-d');
    }
}
