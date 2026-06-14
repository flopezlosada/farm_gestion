<?php
/**
 * Sitio físico donde se entregan las cestas: Torremocha, Cascorro, Midori.
 * Un nodo agrupa N WeeklyBasketGroup y define el día de la semana y la
 * cadencia (semanal o quincenal) con la que reparte. Para nodos quincenales
 * anchor_date marca un viernes-ciclo que SÍ reparte: a partir de ahí
 * alternan semanas operativas vs vacías.
 *
 * Modelado en sub-fase 8.8a (2026-05-26).
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * App\Entity\Node
 *
 * @ORM\Table(name="node", uniqueConstraints={@ORM\UniqueConstraint(name="UNIQ_node_name", columns={"name"})})
 * @ORM\Entity(repositoryClass="App\Repository\NodeRepository")
 */
class Node
{
    public const CADENCE_WEEKLY = 'weekly';
    public const CADENCE_BIWEEKLY = 'biweekly';
    public const CADENCES = [self::CADENCE_WEEKLY, self::CADENCE_BIWEEKLY];

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    /**
     * Día de la semana de reparto en formato ISO-8601: 1=Lunes ... 7=Domingo.
     * Coincide con el resultado de DateTime::format('N').
     *
     * @ORM\Column(name="delivery_weekday", type="smallint")
     */
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    private int $deliveryWeekday;

    /**
     * Cadencia de reparto: 'weekly' o 'biweekly'.
     *
     * @ORM\Column(type="string", length=16)
     */
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::CADENCES, message: 'Cadencia inválida (solo weekly o biweekly).')]
    private string $cadence = self::CADENCE_WEEKLY;

    /**
     * Sólo aplica si cadence='biweekly'. Define un viernes-Basket de referencia
     * que SÍ reparte; el resto de viernes-ciclo se infieren alternando.
     *
     * @ORM\Column(name="anchor_date", type="date", nullable=true)
     */
    private ?\DateTimeInterface $anchorDate = null;

    /**
     * Horario público de recogida, texto libre que se muestra tal cual en la
     * web pública (página "Hazte socix"). Ej.: «Miércoles de 18:00 a 20:00».
     * NULL = no se muestra horario para este nodo.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $schedule = null;

    /**
     * Grupos de socios (proximidad) que cuelgan de este nodo.
     *
     * @ORM\OneToMany(targetEntity="WeeklyBasketGroup", mappedBy="node")
     */
    private $weeklyBasketGroups;

    public function __construct()
    {
        $this->weeklyBasketGroups = new ArrayCollection();
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
        return $this->name ?? null;
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
     * @return int|null Día ISO 1=Lunes..7=Domingo.
     */
    public function getDeliveryWeekday(): ?int
    {
        return $this->deliveryWeekday ?? null;
    }

    /**
     * @param int $deliveryWeekday Día ISO 1=Lunes..7=Domingo.
     * @return self
     */
    public function setDeliveryWeekday(int $deliveryWeekday): self
    {
        $this->deliveryWeekday = $deliveryWeekday;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCadence(): ?string
    {
        return $this->cadence;
    }

    /**
     * @param string $cadence Uno de Node::CADENCE_*.
     * @return self
     * @throws \InvalidArgumentException
     */
    public function setCadence(string $cadence): self
    {
        if (!in_array($cadence, self::CADENCES, true)) {
            throw new \InvalidArgumentException(sprintf('Cadencia inválida: %s', $cadence));
        }
        $this->cadence = $cadence;
        return $this;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getAnchorDate(): ?\DateTimeInterface
    {
        return $this->anchorDate;
    }

    /**
     * @param \DateTimeInterface|null $anchorDate
     * @return self
     */
    public function setAnchorDate(?\DateTimeInterface $anchorDate): self
    {
        $this->anchorDate = $anchorDate;
        return $this;
    }

    /**
     * @return string|null Horario público de recogida, o null si no se publica.
     */
    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    /**
     * @param string|null $schedule Horario público de recogida (texto libre).
     * @return self
     */
    public function setSchedule(?string $schedule): self
    {
        $this->schedule = $schedule;
        return $this;
    }

    /**
     * @return Collection|WeeklyBasketGroup[]
     */
    public function getWeeklyBasketGroups(): Collection
    {
        return $this->weeklyBasketGroups;
    }

    public function addWeeklyBasketGroup(WeeklyBasketGroup $weeklyBasketGroup): self
    {
        if (!$this->weeklyBasketGroups->contains($weeklyBasketGroup)) {
            $this->weeklyBasketGroups[] = $weeklyBasketGroup;
            $weeklyBasketGroup->setNode($this);
        }
        return $this;
    }

    public function removeWeeklyBasketGroup(WeeklyBasketGroup $weeklyBasketGroup): self
    {
        if ($this->weeklyBasketGroups->contains($weeklyBasketGroup)) {
            $this->weeklyBasketGroups->removeElement($weeklyBasketGroup);
            if ($weeklyBasketGroup->getNode() === $this) {
                $weeklyBasketGroup->setNode(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->getName() ?? '';
    }
}
