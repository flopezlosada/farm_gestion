<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un socix apuntado a una oferta de voluntariado, con los acompañantes que
 * traiga. Es la fila de la que sale todo lo demás: las plazas que quedan libres
 * en la oferta y las horas que cada socix lleva hechas.
 *
 * SE APUNTA UN SOCIX, NO UNA FAMILIA. La cesta es de la unidad familiar, pero
 * el trabajo lo hace una persona y el objetivo del módulo es que cada cual vea
 * lo suyo. Los acompañantes van como número (`companions`) y no como filas
 * propias porque no todos son socixs —criaturas, una amistad que se apunta al
 * plan— y darles ficha sería inventarse gente que no existe en el sistema.
 * Agregar por familia, si algún día hace falta, se hace leyendo `parent_id` de
 * {@see Partner}; al revés no se podría.
 *
 * UNICIDAD (offer, partner): sin ella, el doble submit —dar dos veces al botón,
 * volver atrás en el navegador— deja a alguien apuntado dos veces y ocupando
 * dos plazas de una oferta que igual sólo tenía tres. Es exactamente la carrera
 * que ya reventó {@see PartnerDeliveryShift} en su día, y se arregla igual: en
 * la BBDD, no por convención del código. Darse de baja NO borra la fila, la
 * marca ({@see $cancelledAt}) — hace falta saber que alguien se descolgó para
 * volver a pedir gente, y borrarla dejaría la plaza libre en silencio.
 *
 * LAS HORAS SE CONGELAN AQUÍ. `creditedMinutes` copia lo que valía la oferta en
 * el momento de darla por hecha. Si se leyera siempre de la oferta, cambiar más
 * tarde lo que vale ese trabajo reescribiría el histórico de todo el mundo.
 *
 * @ORM\Table(name="volunteer_signup", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_volunteer_signup", columns={"offer_id", "partner_id"})
 * }, indexes={
 *     @ORM\Index(name="idx_volunteer_signup_partner", columns={"partner_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerSignupRepository")
 */
class VolunteerSignup
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerOffer", inversedBy="signups")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?VolunteerOffer $offer = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Partner")
     * @ORM\JoinColumn(name="partner_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?Partner $partner = null;

    /**
     * Personas que vienen además de quien se apunta. Cero es lo normal.
     *
     * @ORM\Column(type="integer", options={"default": 0})
     */
    #[Assert\PositiveOrZero(message: 'Los acompañantes no pueden ser un número negativo.')]
    private int $companions = 0;

    /**
     * Lo que quiera decir quien se apunta ("llego media hora tarde", "llevo
     * furgoneta"). Lo lee quien coordina.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * Si finalmente fue. Null mientras no se sabe —lo normal hasta que pasa el
     * día—, y ese null es significativo: sólo cuentan horas las inscripciones
     * confirmadas, así que olvidarse de cerrar una oferta no infla el contador
     * de nadie.
     *
     * @ORM\Column(type="boolean", nullable=true)
     */
    private ?bool $attended = null;

    /**
     * Minutos reconocidos a esta persona, congelados al dar la oferta por
     * hecha. Null mientras no se ha cerrado.
     *
     * @ORM\Column(name="credited_minutes", type="integer", nullable=true)
     */
    #[Assert\PositiveOrZero]
    private ?int $creditedMinutes = null;

    /**
     * Cuándo se dio de baja, o null si sigue apuntada.
     *
     * @ORM\Column(name="cancelled_at", type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $cancelledAt = null;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    /**
     * Personas que aporta esta inscripción: quien se apunta más acompañantes.
     *
     * @return int cabezas comprometidas
     */
    public function getHeadcount(): int
    {
        return 1 + $this->companions;
    }

    /**
     * Si esta inscripción se dio de baja.
     *
     * @return bool true si está cancelada
     */
    public function isCancelled(): bool
    {
        return null !== $this->cancelledAt;
    }

    /**
     * Da de baja la inscripción sin borrarla, dejando fecha.
     *
     * @param \DateTimeInterface|null $when cuándo se da de baja; por defecto, ahora
     */
    public function cancel(?\DateTimeInterface $when = null): self
    {
        $this->cancelledAt = $when ?? new \DateTime();

        return $this;
    }

    /**
     * Da la inscripción por cumplida y congela lo que computa, tomándolo de la
     * oferta si no se indica otra cosa (alguien que se quedó media jornada).
     *
     * Una inscripción cancelada no se puede dar por cumplida: sería contarle
     * horas a quien avisó de que no iba.
     *
     * @param int|null $minutes minutos a reconocer; null para tomar los de la oferta
     *
     * @throws \LogicException si la inscripción está cancelada
     */
    public function confirmAttendance(?int $minutes = null): self
    {
        if ($this->isCancelled()) {
            throw new \LogicException('No se pueden computar horas de una inscripción cancelada.');
        }

        $this->attended = true;
        $this->creditedMinutes = $minutes ?? $this->offer?->getCreditedMinutes();

        return $this;
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return VolunteerOffer|null la oferta a la que se apunta
     */
    public function getOffer(): ?VolunteerOffer
    {
        return $this->offer;
    }

    /**
     * @param VolunteerOffer|null $offer la oferta a la que se apunta
     */
    public function setOffer(?VolunteerOffer $offer): self
    {
        $this->offer = $offer;

        return $this;
    }

    /**
     * @return Partner|null quién se apunta
     */
    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    /**
     * @param Partner|null $partner quién se apunta
     */
    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    /**
     * @return int acompañantes que trae
     */
    public function getCompanions(): int
    {
        return $this->companions;
    }

    /**
     * @param int $companions acompañantes que trae
     */
    public function setCompanions(int $companions): self
    {
        $this->companions = $companions;

        return $this;
    }

    /**
     * @return string|null lo que dijo quien se apunta, o null
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @param string|null $notes lo que dice quien se apunta
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return bool|null true/false si se sabe, null si aún no se ha cerrado
     */
    public function getAttended(): ?bool
    {
        return $this->attended;
    }

    /**
     * @param bool|null $attended si finalmente fue; null para dejarlo sin cerrar
     */
    public function setAttended(?bool $attended): self
    {
        $this->attended = $attended;

        return $this;
    }

    /**
     * @return int|null minutos reconocidos, o null si no se ha cerrado
     */
    public function getCreditedMinutes(): ?int
    {
        return $this->creditedMinutes;
    }

    /**
     * @param int|null $creditedMinutes minutos reconocidos
     */
    public function setCreditedMinutes(?int $creditedMinutes): self
    {
        $this->creditedMinutes = $creditedMinutes;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null cuándo se dio de baja, o null
     */
    public function getCancelledAt(): ?\DateTimeInterface
    {
        return $this->cancelledAt;
    }

    /**
     * @return \DateTimeInterface cuándo se apuntó
     */
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
