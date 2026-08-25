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
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
     * Día ISO-8601 (el que devuelve DateTime::format('N')) a nombre humano.
     * Punto único: lo consumen el formulario del nodo y las etiquetas de
     * fechas del formulario de cesta.
     *
     * @var array<int,string>
     */
    public const WEEKDAY_NAMES = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

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
     * Sólo aplica si cadence='biweekly'. Fecha de una entrega REAL del nodo
     * que SÍ reparte; el resto se infieren alternando desde ahí.
     *
     * Debe caer en el mismo día de la semana que `delivery_weekday`: la
     * alineación quincenal se calcula comparando esta fecha con la fecha
     * FÍSICA del nodo ({@see \App\Service\Delivery\NodeDeliveryDate}), así que
     * un ancla en otro día de la semana desplaza la cuenta de semanas e
     * invierte la fase — el nodo repartiría justo las semanas contrarias.
     * Lo garantiza {@see validateCadenceConsistency()}.
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

    /**
     * Coherencia entre cadencia y fecha ancla. Sin esto se puede guardar un
     * nodo quincenal sin ancla, y entonces cualquier pantalla que calcule
     * fechas de reparto revienta con un 500 al resolver la alternancia
     * ({@see \App\Service\Delivery\NodeDeliveryDate::physicalDateFor}), que es
     * lo que pasó al dar de alta "El Berrueco" el 25-08-2026.
     *
     * Tres reglas:
     *  1. Quincenal exige ancla — sin ella la alternancia es incalculable.
     *  2. El ancla debe caer en el día de reparto del nodo, o la fase se
     *     invierte en silencio (ver {@see $anchorDate}).
     *  3. Semanal no admite ancla — un ancla huérfana que sobrevive a un
     *     cambio de cadencia reaparece después con una fase que nadie eligió.
     *
     * @param ExecutionContextInterface $context
     * @return void
     */
    #[Assert\Callback]
    public function validateCadenceConsistency(ExecutionContextInterface $context): void
    {
        $isBiweekly = $this->cadence === self::CADENCE_BIWEEKLY;

        if ($isBiweekly && $this->anchorDate === null) {
            $context->buildViolation('Un punto de reparto quincenal necesita una fecha ancla: una fecha en la que sí reparte, para saber qué semanas le tocan.')
                ->atPath('anchorDate')
                ->addViolation();

            return;
        }

        if (!$isBiweekly && $this->anchorDate !== null) {
            $context->buildViolation('La fecha ancla sólo se usa en la cadencia quincenal. Déjala vacía.')
                ->atPath('anchorDate')
                ->addViolation();

            return;
        }

        if (!$isBiweekly || !isset($this->deliveryWeekday)) {
            return;
        }

        $anchorWeekday = (int) $this->anchorDate->format('N');
        if ($anchorWeekday !== $this->deliveryWeekday) {
            $context->buildViolation(sprintf(
                'La fecha ancla debe caer en %s, que es el día de reparto de este punto (has elegido un %s).',
                self::WEEKDAY_NAMES[$this->deliveryWeekday] ?? '(día no válido)',
                mb_strtolower(self::WEEKDAY_NAMES[$anchorWeekday] ?? '(día no válido)'),
            ))
                ->atPath('anchorDate')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->getName() ?? '';
    }
}
