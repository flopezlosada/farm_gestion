<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Rastro anónimo de una visita a una página de la zona privada (panel del
 * socix o gestión). Una fila por petición servida bajo /panel o /gestion.
 *
 * Telemetría de producto, no de negocio: sirve para saber qué pantallas se
 * usan, cuáles fallan (statusCode >= 400) y cómo se mueve una visita entre
 * pantallas (agrupando por sessionHash), para decidir qué mejorar.
 *
 * Privacy by design (minimización LOPD): NO guarda IP, NO guarda user-agent,
 * NO guarda la URL con sus ids, NO guarda quién es el socix. El sessionHash es
 * un hash no reversible del id de sesión: permite reconstruir el "viaje" de una
 * visita sin identificar a la persona. Si en el futuro se quiere identificar al
 * socix (p.ej. para detectar quién se atasca y ayudarle), se añade una columna
 * partner_id nullable: el modelo está pensado para ampliarse sin reescribir.
 *
 * La escritura NO pasa por el EntityManager: el UsageTrackingSubscriber inserta
 * por DBAL en kernel.terminate para no ensuciar el UnitOfWork de la request ni
 * romperse si el EM quedó cerrado tras un error. Esta entidad existe para el
 * mapeo del schema y para LEER el rastro desde la pantalla de estadísticas.
 *
 * @ORM\Table(name="usage_hit", indexes={
 *     @ORM\Index(name="idx_usage_hit_area_route", columns={"area", "route_name"}),
 *     @ORM\Index(name="idx_usage_hit_occurred", columns={"occurred_at"}),
 *     @ORM\Index(name="idx_usage_hit_status", columns={"status_code"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\UsageHitRepository")
 */
class UsageHit
{
    /** Zona privada a la que pertenece la ruta visitada. */
    public const AREA_PANEL = 'panel';
    public const AREA_GESTION = 'gestion';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * "panel" o "gestion". Derivado del prefijo del path. Separa el uso de
     * los socixs del uso del equipo de gestión.
     * @ORM\Column(type="string", length=16)
     */
    private string $area;

    /**
     * Nombre de ruta de Symfony (p.ej. "panel_cesta_show"), no la URL. Más
     * estable y agrupa solo: distintos ids bajo la misma pantalla cuentan
     * juntos. Null cuando no hubo ruta que casara (típicamente un 404).
     * @ORM\Column(name="route_name", type="string", length=120, nullable=true)
     */
    private ?string $routeName = null;

    /**
     * Verbo HTTP (GET, POST…). Un POST repetido a la misma ruta sin avanzar
     * es la pista de fricción en un formulario.
     * @ORM\Column(type="string", length=10)
     */
    private string $method;

    /**
     * Código de respuesta HTTP. 4xx/5xx = error visible para el usuario.
     * @ORM\Column(name="status_code", type="smallint")
     */
    private int $statusCode;

    /**
     * Hash no reversible del id de sesión (sha256 con el secret de la app).
     * Correlaciona las peticiones de una misma visita SIN identificar a la
     * persona. Null si la petición no llevaba sesión.
     * @ORM\Column(name="session_hash", type="string", length=64, nullable=true)
     */
    private ?string $sessionHash = null;

    /**
     * @ORM\Column(name="occurred_at", type="datetime")
     */
    private \DateTimeInterface $occurredAt;

    public function __construct(
        string $area,
        ?string $routeName,
        string $method,
        int $statusCode,
        ?string $sessionHash,
        ?\DateTimeInterface $occurredAt = null,
    ) {
        $this->area = $area;
        $this->routeName = $routeName;
        $this->method = $method;
        $this->statusCode = $statusCode;
        $this->sessionHash = $sessionHash;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArea(): string
    {
        return $this->area;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getSessionHash(): ?string
    {
        return $this->sessionHash;
    }

    public function getOccurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }
}
