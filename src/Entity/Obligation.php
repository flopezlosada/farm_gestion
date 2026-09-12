<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Algo que la asociación tiene que mantener VIGENTE y que, si caduca, deja de
 * estar: un convenio de cesión de parcela, el REGA, una póliza, la concesión de
 * un pozo, el nombramiento de la junta directiva.
 *
 * LA ENTIDAD ES LA OBLIGACIÓN, NO LA FECHA, y esa es la decisión que sostiene
 * todo lo demás. Un convenio no se acaba cuando vence: se renueva, y sigue
 * siendo el mismo convenio. Por eso la fecha no es una columna editable de aquí
 * sino el fin del último {@see ObligationTerm}: renovar es AÑADIR un periodo, y
 * con eso el aviso del año que viene se reprograma solo. Con una fecha suelta,
 * alguien tendría que acordarse de moverla a mano el día que se renueva —
 * exactamente el olvido que este registro viene a evitar.
 *
 * De paso, el historial de periodos contesta gratis dos preguntas que hoy nadie
 * puede responder: cada cuánto se renueva esto de verdad, y cuántas veces se ha
 * dejado pasar.
 *
 * @ORM\Table(
 *     name="obligation",
 *     indexes={
 *         @ORM\Index(name="IDX_obligation_archived", columns={"archived"}),
 *         @ORM\Index(name="IDX_obligation_kind", columns={"kind"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\ObligationRepository")
 */
class Obligation
{
    /** Cesión o alquiler de tierra. El que si falta, no hay huerta. */
    public const KIND_AGREEMENT = 'agreement';

    /** Inscripción en un registro oficial: REGA, REGEPA, registro de asociaciones. */
    public const KIND_REGISTRY = 'registry';

    /** Ayuda o subvención con su justificación y sus plazos: PAC, convocatorias. */
    public const KIND_SUBSIDY = 'subsidy';

    /** Póliza de seguro. */
    public const KIND_INSURANCE = 'insurance';

    /** Licencia, autorización o concesión administrativa (pozos, vertidos). */
    public const KIND_LICENSE = 'license';

    /** Contrato con un tercero: proveedor, servicio, arrendamiento. */
    public const KIND_CONTRACT = 'contract';

    /** Prevención de riesgos: plan anual, formación por persona, reconocimientos. */
    public const KIND_PREVENTION = 'prevention';

    /** Cargo u órgano con mandato limitado: junta directiva, representante legal. */
    public const KIND_GOVERNANCE = 'governance';

    /** Tasa o impuesto periódico: maquinaria, IBI, tratamientos obligatorios. */
    public const KIND_TAX = 'tax';

    /** Lo que no encaja en ninguna de las anteriores. */
    public const KIND_OTHER = 'other';

    /**
     * Etiquetas en castellano de cada tipo. Viven aquí y no en la plantilla
     * porque las usan también el selector del formulario y el correo del aviso:
     * con tres copias, una se queda vieja.
     *
     * @var array<string, string>
     */
    public const KIND_LABELS = [
        self::KIND_AGREEMENT  => 'Convenio de tierra',
        self::KIND_REGISTRY   => 'Registro oficial',
        self::KIND_SUBSIDY    => 'Ayuda o subvención',
        self::KIND_INSURANCE  => 'Seguro',
        self::KIND_LICENSE    => 'Licencia o concesión',
        self::KIND_CONTRACT   => 'Contrato',
        self::KIND_PREVENTION => 'Prevención de riesgos',
        self::KIND_GOVERNANCE => 'Junta y cargos',
        self::KIND_TAX        => 'Tasa o impuesto',
        self::KIND_OTHER      => 'Otros',
    ];

    /** Vigente y sin nada que hacer todavía. */
    public const STATE_VALID = 'valid';

    /** Vence dentro del plazo de aviso: hay que ponerse. */
    public const STATE_DUE = 'due';

    /** La fecha ya pasó. */
    public const STATE_EXPIRED = 'expired';

    /**
     * No consta ningún periodo. No es lo mismo que estar vencida y no debe
     * pintarse en rojo: es una ficha a medias, y tratarla como avería enseña a
     * no mirar los rojos.
     */
    public const STATE_UNKNOWN = 'unknown';

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * Cómo la llama la gente, no su denominación legal: "Convenio parcela de
     * La Cerrada" se encuentra; "Addenda 2ª al convenio de cesión" no.
     *
     * @ORM\Column(name="name", type="string", length=255)
     */
    private string $name = '';

    /**
     * @ORM\Column(name="kind", type="string", length=20)
     */
    private string $kind = self::KIND_OTHER;

    /**
     * Con quién: el ayuntamiento, la aseguradora, la propiedad de la finca.
     *
     * @ORM\Column(name="counterparty", type="string", length=255, nullable=true)
     */
    private ?string $counterparty = null;

    /**
     * Número de póliza, de expediente o de registro. Es el dato que siempre hay
     * que buscar en el documento cuando se llama por teléfono.
     *
     * @ORM\Column(name="reference", type="string", length=120, nullable=true)
     */
    private ?string $reference = null;

    /**
     * @ORM\Column(name="notes", type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * Enlace al documento donde vive de verdad (Dropbox, sede electrónica). No
     * se sube el fichero: el archivo de la asociación ya existe y duplicarlo
     * garantiza que una de las dos copias quede vieja.
     *
     * @ORM\Column(name="document_url", type="string", length=500, nullable=true)
     */
    private ?string $documentUrl = null;

    /**
     * Quién se ocupa de renovarla. Opcional a propósito: obligar a nombrar
     * responsable el día del alta haría que no se diera de alta. Cuando lo hay,
     * el aviso le llega también a esa persona.
     *
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="responsible_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $responsible = null;

    /**
     * Con cuántos días de antelación hay que PONERSE, cuando el propio documento
     * lo impone. Nulo = basta con los escalones generales.
     *
     * No es una preferencia de aviso: es una cláusula. Los dos arrendamientos
     * rústicos obligan a comunicar la no renovación con UN AÑO de antelación, y
     * la cesión de La Cerrada con un mes. Con sólo los escalones generales —el
     * más lejano son 90 días— el aviso de esos contratos llegaría cuando ya no
     * se puede hacer nada, que es la peor forma de fallar que tiene un
     * recordatorio: puntual e inútil.
     *
     * @ORM\Column(name="lead_days", type="integer", nullable=true)
     */
    private ?int $leadDays = null;

    /**
     * Archivada: ya no aplica (se rescindió el convenio, se vendió la máquina).
     * No se borra, porque su historial de periodos es justo lo que hace falta
     * para responder "¿desde cuándo no tenemos esto?".
     *
     * @ORM\Column(name="archived", type="boolean", options={"default": false})
     */
    private bool $archived = false;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * Los periodos de validez, del más reciente al más antiguo.
     *
     * @var Collection<int, ObligationTerm>
     *
     * @ORM\OneToMany(targetEntity="ObligationTerm", mappedBy="obligation", cascade={"persist", "remove"})
     * @ORM\OrderBy({"endsOn": "DESC"})
     */
    private Collection $terms;

    public function __construct()
    {
        $this->terms = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * El periodo que manda: el de fecha de fin más lejana.
     *
     * Se coge el último y NO "el que contiene a hoy" a propósito. Cuando una
     * renovación se firma con antelación conviven dos periodos válidos, y el que
     * hay que vigilar es el nuevo; con la otra regla, el sistema seguiría
     * avisando del viejo y la renovación ya hecha no serviría de nada.
     */
    public function currentTerm(): ?ObligationTerm
    {
        $best = null;
        foreach ($this->terms as $term) {
            if ($best === null || $term->getEndsOn() > $best->getEndsOn()) {
                $best = $term;
            }
        }

        return $best;
    }

    /**
     * Cuándo caduca lo que hay firmado hoy, o null si no consta ningún periodo.
     */
    public function expiresOn(): ?\DateTimeImmutable
    {
        return $this->currentTerm()?->getEndsOn();
    }

    /**
     * Días que quedan hasta el vencimiento: 0 si vence hoy, negativo si ya pasó,
     * null si no hay fecha.
     *
     * Se compara a medianoche en los dos lados para que el resultado no dependa
     * de la hora a la que corra la tarea.
     *
     * @param \DateTimeInterface|null $today Día de referencia (por defecto, hoy).
     */
    public function daysLeft(?\DateTimeInterface $today = null): ?int
    {
        $expiry = $this->expiresOn();
        if ($expiry === null) {
            return null;
        }

        $from = \DateTimeImmutable::createFromInterface($today ?? new \DateTimeImmutable())->setTime(0, 0);

        return (int) $from->diff($expiry->setTime(0, 0))->format('%r%a');
    }

    /**
     * En qué estado está.
     *
     * El plazo que manda es el MAYOR entre el general y el que exija el propio
     * documento: un contrato que obliga a avisar con un año no puede seguir
     * pintándose en verde a los seis meses porque el escalón general sean 90
     * días.
     *
     * @param int                     $noticeDays Plazo general de aviso.
     * @param \DateTimeInterface|null $today      Día de referencia (por defecto, hoy).
     * @return string Uno de los self::STATE_*.
     */
    public function state(int $noticeDays, ?\DateTimeInterface $today = null): string
    {
        $daysLeft = $this->daysLeft($today);
        $threshold = max($noticeDays, $this->leadDays ?? 0);

        return match (true) {
            $daysLeft === null    => self::STATE_UNKNOWN,
            $daysLeft < 0         => self::STATE_EXPIRED,
            $daysLeft <= $threshold => self::STATE_DUE,
            default               => self::STATE_VALID,
        };
    }

    /**
     * Etiqueta en castellano de su tipo.
     */
    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? self::KIND_LABELS[self::KIND_OTHER];
    }

    /**
     * Añade un periodo de validez y lo deja enlazado por los dos lados.
     *
     * @param ObligationTerm $term Periodo que se incorpora.
     */
    public function addTerm(ObligationTerm $term): self
    {
        if (!$this->terms->contains($term)) {
            $this->terms->add($term);
            $term->setObligation($this);
        }

        return $this;
    }

    /**
     * Retira un periodo (una fecha mal tecleada).
     *
     * @param ObligationTerm $term Periodo que se retira.
     */
    public function removeTerm(ObligationTerm $term): self
    {
        $this->terms->removeElement($term);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name Nombre con el que se busca.
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @param string $kind Uno de los self::KIND_*.
     */
    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getCounterparty(): ?string
    {
        return $this->counterparty;
    }

    /**
     * @param string|null $counterparty Con quién se tiene firmado.
     */
    public function setCounterparty(?string $counterparty): self
    {
        $this->counterparty = $counterparty;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    /**
     * @param string|null $reference Número de póliza, expediente o registro.
     */
    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @param string|null $notes Lo que haya que saber para renovarla.
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function getDocumentUrl(): ?string
    {
        return $this->documentUrl;
    }

    /**
     * @param string|null $documentUrl Enlace al documento en el archivo de la asociación.
     */
    public function setDocumentUrl(?string $documentUrl): self
    {
        $this->documentUrl = $documentUrl;

        return $this;
    }

    public function getResponsible(): ?User
    {
        return $this->responsible;
    }

    /**
     * @param User|null $responsible Quien se ocupa de renovarla.
     */
    public function setResponsible(?User $responsible): self
    {
        $this->responsible = $responsible;

        return $this;
    }

    public function getLeadDays(): ?int
    {
        return $this->leadDays;
    }

    /**
     * @param int|null $leadDays Antelación que exige el documento, en días.
     */
    public function setLeadDays(?int $leadDays): self
    {
        $this->leadDays = $leadDays;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    /**
     * @param bool $archived true para retirarla de la vigilancia sin perder su historial.
     */
    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, ObligationTerm>
     */
    public function getTerms(): Collection
    {
        return $this->terms;
    }
}
