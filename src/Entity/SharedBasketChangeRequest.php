<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un hogar pide a el otro un cambio en la cesta que comparten, y espera su respuesta.
 *
 * EXISTE PORQUE LA CESTA ES UNA. Dos familias se reparten una cesta física: su día y
 * su punto no son datos de cada hogar sino de la pareja (leyes L5 y L22), así que
 * ninguno de los dos puede cambiarlos por su cuenta. Hasta ahora eso se resolvía
 * prohibiéndolo; esta tabla es la otra salida — pedirlo, y que lo aplique la
 * conformidad del otro.
 *
 * NADA CAMBIA MIENTRAS ESTÁ PENDIENTE. La petición no toca el reparto: lo aplica
 * {@see \App\Service\Delivery\SharedPairDeliveryEditor} en el momento de aceptar, y
 * volviendo a validar. Aplicar antes y deshacer si hay negativa no vale: entre medias
 * el listado del nodo puede haberse generado, impreso y salido por correo.
 *
 * CADUCA SOLA, SIN TAREA QUE LA BARRA. Una petición sobre una semana cuyo plazo ya
 * cerró no es "pendiente", es agua pasada: el estado se deriva al leerla
 * ({@see isActionable()}) en vez de escribirlo. Una tarea programada para marcar filas
 * que nadie va a mirar es trabajo que se puede no hacer.
 *
 * @ORM\Table(name="shared_basket_change_request", indexes={
 *     @ORM\Index(name="idx_sbcr_counterpart_status", columns={"counterpart_id", "status"}),
 *     @ORM\Index(name="idx_sbcr_requester_status", columns={"requester_id", "status"}),
 *     @ORM\Index(name="idx_sbcr_basket", columns={"basket_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\SharedBasketChangeRequestRepository")
 */
class SharedBasketChangeRequest
{
    /** Cambiar el día de un reparto concreto. */
    public const KIND_MOVE = 'move';

    /** Recoger ese reparto en otro punto. */
    public const KIND_RELOCATE = 'relocate';

    /**
     * Cambiar la modalidad o el turno, de forma permanente.
     *
     * Aceptarla NO la aplica: lleva cuota detrás y la ley L22 obliga a cambiar las dos
     * suscripciones a la vez. Lo que aporta es lo que hoy se hace a mano — que los dos
     * hogares digan que sí antes de que administración toque nada.
     */
    public const KIND_MODALITY = 'modality';

    /** Esperando la respuesta del otro hogar. */
    public const STATUS_PENDING = 'pending';

    /** El otro hogar dijo que sí; el cambio se aplicó en ese momento. */
    public const STATUS_ACCEPTED = 'accepted';

    /** El otro hogar dijo que no. */
    public const STATUS_REJECTED = 'rejected';

    /** Quien la pidió se echó atrás antes de tener respuesta. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Hogar que pide el cambio.
     *
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="requester_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Partner $requester = null;

    /**
     * Hogar que tiene que decidir.
     *
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="counterpart_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Partner $counterpart = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $kind = self::KIND_MOVE;

    /**
     * Semana afectada: la de origen en un cambio de día, la que se traslada en un
     * cambio de punto. Null en un cambio de modalidad, que no es de una semana.
     *
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="basket_id", nullable=true, onDelete="CASCADE")
     */
    private ?Basket $basket = null;

    /**
     * Semana destino de un cambio de día.
     *
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="to_basket_id", nullable=true, onDelete="CASCADE")
     */
    private ?Basket $toBasket = null;

    /**
     * Punto de recogida destino de un traslado.
     *
     * @ORM\ManyToOne(targetEntity="WeeklyBasketGroup")
     * @ORM\JoinColumn(name="target_group_id", nullable=true, onDelete="CASCADE")
     */
    private ?WeeklyBasketGroup $targetGroup = null;

    /**
     * Lo pedido en un cambio de modalidad, tal cual lo eligió quien pide
     * (basket_share_id, delivery_group, day_month_order). No se aplica desde aquí:
     * es lo que administración lee para saber qué acordaron.
     *
     * @ORM\Column(type="json", nullable=true)
     *
     * @var array<string, mixed>|null
     */
    private ?array $payload = null;

    /**
     * Recado de quien pide, para el otro hogar. Es lo que convierte "cambio del 19 al
     * 26" en algo que se puede aceptar sin llamar por teléfono.
     *
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    #[Assert\Length(max: 500)]
    private ?string $note = null;

    /**
     * @ORM\Column(type="string", length=12)
     */
    private string $status = self::STATUS_PENDING;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $decidedAt = null;

    /**
     * @param Partner $requester   Hogar que pide.
     * @param Partner $counterpart Hogar que decide.
     * @param string  $kind        Una de las constantes KIND_*.
     */
    public function __construct(Partner $requester, Partner $counterpart, string $kind)
    {
        $this->requester = $requester;
        $this->counterpart = $counterpart;
        $this->kind = $kind;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequester(): ?Partner
    {
        return $this->requester;
    }

    public function getCounterpart(): ?Partner
    {
        return $this->counterpart;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function setBasket(?Basket $basket): self
    {
        $this->basket = $basket;

        return $this;
    }

    public function getToBasket(): ?Basket
    {
        return $this->toBasket;
    }

    public function setToBasket(?Basket $toBasket): self
    {
        $this->toBasket = $toBasket;

        return $this;
    }

    public function getTargetGroup(): ?WeeklyBasketGroup
    {
        return $this->targetGroup;
    }

    public function setTargetGroup(?WeeklyBasketGroup $targetGroup): self
    {
        $this->targetGroup = $targetGroup;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $note = null !== $note ? trim($note) : null;
        $this->note = '' !== $note ? $note : null;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /** Si sigue esperando respuesta. */
    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    /**
     * Si todavía se puede decidir: pendiente Y con la semana por delante.
     *
     * La fecha se compara contra el día del ciclo y no contra la física del nodo: es
     * un filtro barato para no ofrecer botones muertos, y el plazo de verdad —el que
     * manda— lo vuelve a comprobar el editor al aplicar. Una petición de modalidad no
     * cuelga de ninguna semana, así que sigue viva hasta que alguien conteste.
     *
     * @param \DateTimeImmutable|null $today Día de referencia; hoy si no se pasa.
     */
    public function isActionable(?\DateTimeImmutable $today = null): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $date = $this->basket?->getDate();
        if (null === $date) {
            return true;
        }

        return $date > ($today ?? new \DateTimeImmutable('today'));
    }

    /**
     * Lo que se pide, en una línea y en cristiano.
     *
     * Vive en la entidad porque lo piden tres sitios —el correo, la bandeja y la
     * pantalla— y escrito tres veces acabarían diciendo tres cosas distintas del
     * mismo cambio. Fechas en formato corto: el aviso ya dice de qué cesta habla.
     *
     * @return string Una frase, sin punto final.
     */
    public function summary(): string
    {
        $when = $this->basket?->getDate()?->format('j/n') ?? 'un reparto';

        return match ($this->kind) {
            self::KIND_MOVE => sprintf(
                'cambiar la cesta del %s al %s',
                $when,
                $this->toBasket?->getDate()?->format('j/n') ?? 'otro día',
            ),
            self::KIND_RELOCATE => sprintf(
                'recoger la cesta del %s en %s',
                $when,
                $this->targetGroup?->getName() ?? 'otro punto',
            ),
            default => 'cambiar la modalidad de vuestra cesta',
        };
    }

    /** Marca la conformidad del otro hogar. El cambio lo aplica quien llama. */
    public function accept(): self
    {
        return $this->decide(self::STATUS_ACCEPTED);
    }

    /** Marca la negativa del otro hogar. */
    public function reject(): self
    {
        return $this->decide(self::STATUS_REJECTED);
    }

    /** Marca que quien la pidió se echó atrás. */
    public function cancel(): self
    {
        return $this->decide(self::STATUS_CANCELLED);
    }

    /**
     * Cierra la petición con un desenlace. Una ya cerrada no se reabre ni se
     * recalifica: la respuesta del otro hogar es un hecho con fecha, y dejar que un
     * segundo clic la cambie es cómo una negativa acaba aplicada.
     *
     * @param string $status Uno de los STATUS_* de cierre.
     *
     * @throws \LogicException Si ya estaba cerrada.
     */
    private function decide(string $status): self
    {
        if (!$this->isPending()) {
            throw new \LogicException('Esa petición ya está resuelta.');
        }

        $this->status = $status;
        $this->decidedAt = new \DateTimeImmutable();

        return $this;
    }
}
