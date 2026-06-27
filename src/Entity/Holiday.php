<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Día festivo del calendario laboral de la asociación. Es un día NO laborable
 * que no es ni fin de semana ni ausencia: sin él, un festivo entre semana sin
 * fichaje aparecería como {@see App\Service\Staff\GapFinder hueco} falso del
 * registro de jornada, y la Inspección lo vería como un olvido.
 *
 * Global (no por trabajador): los trabajadores de la asociación comparten centro
 * de trabajo, así que el calendario es único. Si algún día hubiera centros con
 * festivos locales distintos, se modelaría el alcance entonces (YAGNI).
 *
 * Se gestionan a mano (≈14 al año): nacionales + autonómicos de Madrid + locales.
 * No se autogeneran porque los autonómicos/locales cambian cada año y meterlos a
 * mano es trivial frente a mantener un generador.
 *
 * @ORM\Table(name="holiday")
 * @ORM\Entity(repositoryClass="App\Repository\HolidayRepository")
 */
class Holiday
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
     * Fecha del festivo. Única: no tiene sentido dos festivos el mismo día.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="date", type="date_immutable", unique=true)
     */
    #[Assert\NotNull]
    private $date;

    /**
     * Nombre del festivo (p. ej. "Día de la Comunidad de Madrid"). Informativo,
     * para mostrarlo en el calendario.
     *
     * @var string
     * @ORM\Column(name="name", type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private $name;

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @param \DateTimeImmutable $date
     * @return self
     */
    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
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
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->name;
    }
}
