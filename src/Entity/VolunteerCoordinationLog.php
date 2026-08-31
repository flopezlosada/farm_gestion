<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Horas de coordinar un ÁREA, apuntadas por quien la coordina.
 *
 * COORDINAR NO ES DE UNA TAREA, ES DE UN ÁREA. Quien lleva Reparto lo lleva
 * entero —las cincuenta y dos del año— y ese trabajo ya está modelado en
 * {@see VolunteerCategory::$coordinators}. Lo que faltaba no era decir quién
 * coordina, que ya se sabe, sino poder contar las horas de un trabajo que no
 * ocurre un día concreto: buscar gente, cuadrarla, avisar, estar pendiente.
 *
 * POR ESO ES UN PARTE LIBRE y no una tarea que cerrar. Una
 * {@see VolunteerOffer} tiene fecha, plazas, avisos y gente que se apunta;
 * coordinar no tiene nada de eso. Forzarlo a ese molde llenaría el listado de
 * tareas fantasma que no piden a nadie.
 *
 * LO APUNTA QUIEN COORDINA, desde su panel, igual que quien va a una tarea dice
 * si fue. Nadie más sabe cuántas horas le ha llevado, y que lo pusiera gestión
 * sería inventárselo.
 *
 * Cuelga de `partner` y no de `fos_user` —aunque la coordinación del área
 * cuelgue de la cuenta— porque las horas son de un socix con ficha, que es
 * quien las tiene en su contador.
 *
 * @ORM\Table(name="volunteer_coordination_log", indexes={
 *     @ORM\Index(name="idx_vcl_partner", columns={"partner_id"}),
 *     @ORM\Index(name="idx_vcl_category", columns={"category_id"}),
 *     @ORM\Index(name="idx_vcl_happened", columns={"happened_on"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerCoordinationLogRepository")
 */
class VolunteerCoordinationLog
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Partner")
     * @ORM\JoinColumn(name="partner_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?Partner $partner = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerCategory")
     * @ORM\JoinColumn(name="category_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?VolunteerCategory $category = null;

    /**
     * Día al que se imputan las horas.
     *
     * Coordinar no ocurre un día concreto, pero las horas tienen que caer en
     * alguno: es lo que decide en qué año cuentan, y el contador va por año
     * natural. Se elige a mano —"esto fue de la semana pasada"— y por defecto
     * es hoy.
     *
     * @ORM\Column(name="happened_on", type="date")
     */
    #[Assert\NotNull(message: 'Hace falta saber a qué día se imputan.')]
    private ?\DateTimeInterface $happenedOn = null;

    /**
     * Minutos enteros, como en el resto del módulo: en pantalla se piden horas
     * y {@see \App\Service\Volunteering\CreditedTime} traduce. Un decimal de
     * Doctrine vuelve del driver como string y acaba sumándose con floats.
     *
     * @ORM\Column(type="integer")
     */
    #[Assert\Positive(message: 'Las horas tienen que ser mayores que cero.')]
    private int $minutes = 0;

    /**
     * En qué se fueron, si quien las apunta quiere dejarlo dicho ("cuadrar los
     * turnos de septiembre"). No hace falta.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $notes = null;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->happenedOn = new \DateTime();
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Partner|null a quién se le computan estas horas
     */
    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    /**
     * @param Partner|null $partner a quién se le computan
     */
    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    /**
     * @return VolunteerCategory|null el área que se coordinó
     */
    public function getCategory(): ?VolunteerCategory
    {
        return $this->category;
    }

    /**
     * @param VolunteerCategory|null $category el área que se coordinó
     */
    public function setCategory(?VolunteerCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null el día al que se imputan
     */
    public function getHappenedOn(): ?\DateTimeInterface
    {
        return $this->happenedOn;
    }

    /**
     * @param \DateTimeInterface $happenedOn el día al que se imputan
     */
    public function setHappenedOn(\DateTimeInterface $happenedOn): self
    {
        $this->happenedOn = $happenedOn;

        return $this;
    }

    /**
     * @return int los minutos apuntados
     */
    public function getMinutes(): int
    {
        return $this->minutes;
    }

    /**
     * @param int $minutes los minutos apuntados
     */
    public function setMinutes(int $minutes): self
    {
        $this->minutes = max(0, $minutes);

        return $this;
    }

    /**
     * @return string|null en qué se fueron, o null
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @param string|null $notes en qué se fueron
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return \DateTimeInterface cuándo se apuntó
     */
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
