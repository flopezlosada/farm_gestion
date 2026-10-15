<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un periodo en que el socix no recibe cesta: desde un día (incluido) hasta el día
 * en que vuelve (excluido), o sin fecha de vuelta mientras siga abierta.
 *
 * Es la FUENTE DE VERDAD de "¿está en pausa esta semana?". La consultan el
 * generador del reparto (para no materializar ni dibujar su entrega), las cuotas
 * (para no cobrarla) y la tarea diaria que pone `partner.status` en PAUSADO o
 * ACTIVO al llegar cada fecha. El estado del socix es el reflejo de HOY; la pausa,
 * el calendario. Por eso una pausa puede programarse a futuro sin que la ficha
 * cambie hasta que llegue su día.
 *
 * La suscripción (PartnerBasketShare) no se toca al pausar: reactivar no obliga a
 * rehacer la cesta. El rastro de quién y cuándo sigue en PartnerEvent (PAUSE /
 * RESUME), que se emite cuando el estado cambia de verdad.
 *
 * El intervalo [startsOn, resumesOn) es semiabierto, el mismo convenio que los
 * periodos sin cobrar de las cuotas: resumesOn es el primer día con cesta.
 *
 * @ORM\Table(name="partner_pause")
 * @ORM\Entity(repositoryClass="App\Repository\PartnerPauseRepository")
 */
class PartnerPause
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", nullable=false, onDelete="CASCADE")
     */
    private Partner $partner;

    /**
     * Primer día sin cesta.
     * @ORM\Column(name="starts_on", type="date_immutable")
     */
    private \DateTimeImmutable $startsOn;

    /**
     * Primer día en que vuelve a recibir cesta; null mientras no se sepa.
     * @ORM\Column(name="resumes_on", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $resumesOn = null;

    /**
     * Quién la registró, con el convenio de PartnerEvent::actor.
     * @ORM\Column(type="string", length=80, nullable=true)
     */
    private ?string $actor = null;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @param Partner                 $partner   Socix en pausa.
     * @param \DateTimeInterface      $startsOn  Primer día sin cesta.
     * @param \DateTimeInterface|null $resumesOn Primer día con cesta otra vez; null si no se sabe.
     * @param string|null             $actor     Quién la registra.
     * @throws \InvalidArgumentException Si la vuelta no es posterior al inicio.
     */
    public function __construct(Partner $partner, \DateTimeInterface $startsOn, ?\DateTimeInterface $resumesOn = null, ?string $actor = null)
    {
        $this->partner = $partner;
        $this->startsOn = self::day($startsOn);
        $this->setResumesOn($resumesOn);
        $this->actor = $actor;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Un instante cualquiera reducido a su día, para comparar fechas sin horas. */
    public static function day(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getResumesOn(): ?\DateTimeImmutable
    {
        return $this->resumesOn;
    }

    /**
     * Fija (o quita) el día de vuelta.
     *
     * @param \DateTimeInterface|null $resumesOn Primer día con cesta; null = sin fecha de vuelta.
     * @throws \InvalidArgumentException Si no es posterior al inicio: una pausa vacía no existe.
     */
    public function setResumesOn(?\DateTimeInterface $resumesOn): self
    {
        $resumesOn = $resumesOn !== null ? self::day($resumesOn) : null;
        if ($resumesOn !== null && $resumesOn <= $this->startsOn) {
            throw new \InvalidArgumentException('La vuelta tiene que ser posterior al inicio de la pausa.');
        }
        $this->resumesOn = $resumesOn;

        return $this;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * ¿Ese día cae dentro de la pausa?
     *
     * @param \DateTimeInterface $date Día a comprobar (se ignora la hora).
     */
    public function covers(\DateTimeInterface $date): bool
    {
        $day = self::day($date);

        return $this->startsOn <= $day && ($this->resumesOn === null || $day < $this->resumesOn);
    }

    /**
     * ¿Se solapa con el periodo [desde, hasta)? Hasta null = sin fin.
     *
     * @param \DateTimeInterface      $from  Inicio del otro periodo.
     * @param \DateTimeInterface|null $until Fin (excluido) del otro periodo, o null.
     */
    public function overlaps(\DateTimeInterface $from, ?\DateTimeInterface $until): bool
    {
        $from = self::day($from);
        $until = $until !== null ? self::day($until) : null;

        return ($until === null || $this->startsOn < $until)
            && ($this->resumesOn === null || $from < $this->resumesOn);
    }

    /** ¿Ya terminó antes de ese día? (sigue en el histórico, pero no cuenta para lo que viene). */
    public function endedBefore(\DateTimeInterface $date): bool
    {
        return $this->resumesOn !== null && $this->resumesOn <= self::day($date);
    }
}
