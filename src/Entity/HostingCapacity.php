<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Aforo del albergue para un mes concreto. El aforo NO es un número único: es
 * un calendario de capacidad, porque varía por mes y hay meses cerrados.
 *
 * Se guarda el aforo OPERATIVO (el que decide la asociación, siempre ≤ el aforo
 * físico real). El físico es sólo un número de referencia global en AppSettings
 * para avisar si un mes se pasa; no participa en ninguna comprobación, así que
 * no se modela como estructura viva (YAGNI).
 *
 * Una estancia que cruza de un mes a otro no necesita nada especial: el
 * {@see \App\Service\Hosting\OccupancyChecker} resuelve el aforo día a día,
 * consultando el mes de cada día.
 *
 * Mes cerrado ({@see HostingCapacity::$open} = false): el albergue no recibe a
 * nadie ese mes. Se distingue del "0 plazas" sólo para pintarlo distinto en el
 * calendario; para el aforo, un mes cerrado equivale a capacidad 0.
 *
 * @ORM\Table(name="hosting_capacity", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_hosting_capacity_year_month", columns={"year", "month"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\HostingCapacityRepository")
 */
class HostingCapacity
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
     * @var int
     * @ORM\Column(name="year", type="smallint")
     */
    #[Assert\NotNull]
    #[Assert\Range(min: 2000, max: 2100)]
    private $year;

    /**
     * @var int
     * @ORM\Column(name="month", type="smallint")
     */
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 12)]
    private $month;

    /**
     * ¿El albergue está abierto ese mes? Si no, no recibe a nadie (aforo 0 a
     * efectos de la comprobación), pero se distingue visualmente de "0 plazas".
     *
     * @var bool
     * @ORM\Column(name="is_open", type="boolean", options={"default": true})
     */
    private $open = true;

    /**
     * Aforo operativo del mes (plazas que la asociación decide ofrecer).
     *
     * @var int
     * @ORM\Column(name="capacity", type="smallint", options={"default": 0})
     */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private $capacity = 0;

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
     * Aforo efectivo del mes a efectos de comprobación: 0 si está cerrado,
     * el aforo operativo en caso contrario.
     *
     * @return int
     */
    public function effectiveCapacity(): int
    {
        return $this->open ? $this->capacity : 0;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return int|null
     */
    public function getYear(): ?int
    {
        return $this->year;
    }

    /**
     * @param int $year
     * @return self
     */
    public function setYear(int $year): self
    {
        $this->year = $year;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getMonth(): ?int
    {
        return $this->month;
    }

    /**
     * @param int $month
     * @return self
     */
    public function setMonth(int $month): self
    {
        $this->month = $month;

        return $this;
    }

    /**
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * @param bool $open
     * @return self
     */
    public function setOpen(bool $open): self
    {
        $this->open = $open;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    /**
     * @param int $capacity
     * @return self
     */
    public function setCapacity(int $capacity): self
    {
        $this->capacity = $capacity;

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
}
