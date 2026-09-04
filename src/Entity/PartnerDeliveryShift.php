<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Intent puntual de entrega de un socio. La pauta normal del socio (semanal,
 * quincenal, mensual con su grupo A/B implícito) sigue intacta; solo se ve
 * afectado lo que describe este intent. Cuatro datos lo definen:
 *   - `component` (nullable): null = toda la entrega; si no, un componente
 *     concreto (verdura / huevos). El caso SANTOS (mensual verdura + semanal
 *     huevo) necesita mover SOLO la verdura sin tocar el huevo semanal.
 *   - `from` = semana origen.
 *   - `to` (nullable): semana destino; null = "no recoge" (sin destino).
 *   - `accumulatedTo` (nullable): solo en intents SIN destino. Distingue una
 *     cesta APARCADA (recolocable, sale en la papelera) de una TRASLADADA
 *     SUMANDO a la entrega de otra semana (ya colocada allí como cesta extra,
 *     no sale en la papelera). Ver el apartado siguiente.
 *
 * Combinaciones: mover toda la entrega (component null, to≠null) — el caso
 * histórico; mover un componente (component≠null, to≠null); no recoger
 * (component null, to null); quitar un componente esa semana (component≠null,
 * to null).
 *
 * POR QUÉ `accumulatedTo` no es un `to`: un día no puede llevar dos
 * WeeklyBasket del mismo socio (unicidad por (partner, basket)), así que
 * "trasladar sumando" no se puede modelar como destino — la segunda cesta vive
 * como {@see PartnerBasketExtra} sobre la entrega que el día ya tenía. El
 * origen necesita igualmente un intent sin destino para que el generador no
 * materialice su cesta de patrón. Sin este campo, ese intent era
 * indistinguible de un "no recoge" y la cesta se contaba DOS veces: sumada en
 * el destino y pendiente en la papelera del origen, de donde alguien podía
 * recuperarla y acabar con una cesta de más en el listado.
 *
 * Esta entidad es la fuente de verdad del intent. Crear o cancelar uno dispara,
 * en la misma transacción, los cambios consecuentes sobre WeeklyBasket (ver
 * App\Service\Delivery\DeliveryShiftApplier).
 *
 * El audit trail de quién y cuándo vive en PartnerEvent (tipo WEEK_SWAP), no
 * aquí. Si admin/socix lo cancela, la fila se borra; los PartnerEvent quedan
 * como histórico inmutable.
 *
 * NOTA unicidad: el unique NO puede ir sobre `component_id` directamente porque
 * MySQL trata los NULL como distintos, así que no impediría dos intents de
 * entrega-entera (component null) con el mismo `from`/`to` — la carrera de doble
 * submit que reventaba findOutgoing/findIncoming con NonUniqueResultException. Se
 * resuelve con la columna GENERADA `component_key` = COALESCE(component_id, 0):
 * los intents de entrega entera comparten clave 0 y colisionan; los de componente
 * (verdura=1, huevos=2) siguen distintos. Así el estado ilegal es irrepresentable
 * a nivel de BBDD, no solo por convención del código.
 *
 * @ORM\Table(name="partner_delivery_shift", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_partner_from_basket", columns={"partner_id", "from_basket_id", "component_key"}),
 *     @ORM\UniqueConstraint(name="uniq_partner_to_basket", columns={"partner_id", "to_basket_id", "component_key"})
 * }, indexes={
 *     @ORM\Index(name="idx_from_basket", columns={"from_basket_id"}),
 *     @ORM\Index(name="idx_to_basket", columns={"to_basket_id"}),
 *     @ORM\Index(name="idx_pds_component", columns={"component_id"}),
 *     @ORM\Index(name="idx_pds_accumulated_to", columns={"accumulated_to_basket_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\PartnerDeliveryShiftRepository")
 */
class PartnerDeliveryShift
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
    #[Assert\NotNull]
    private ?Partner $partner = null;

    /**
     * Viernes original donde el socio dejaría de recoger.
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="from_basket_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Basket $fromBasket = null;

    /**
     * Semana destino donde el socio recoge en su lugar. NULL = "no recoge"
     * (intent de salto, sin destino).
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="to_basket_id", nullable=true, onDelete="CASCADE")
     */
    private ?Basket $toBasket = null;

    /**
     * Semana a cuya entrega se SUMÓ la cesta de esta semana ("trasladar
     * sumando"). Solo tiene sentido con `toBasket` null: el día origen no
     * reparte, pero su cesta no está pendiente —vive como cesta extra
     * ({@see PartnerBasketExtra}) sobre la entrega de esta semana—. NULL en un
     * "no recoge" normal, donde la cesta SÍ queda pendiente y recolocable.
     *
     * Es también el hilo para deshacer el traslado en un gesto: desde el día
     * destino se localizan los intents que le sumaron cestas.
     *
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="accumulated_to_basket_id", nullable=true, onDelete="CASCADE")
     */
    private ?Basket $accumulatedTo = null;

    /**
     * Componente al que se limita el intent. NULL = toda la entrega (caso
     * histórico). Si no, verdura u huevos: el intent solo afecta a ese
     * componente y los demás siguen su propio calendario (caso SANTOS).
     * @ORM\ManyToOne(targetEntity="BasketComponent")
     * @ORM\JoinColumn(name="component_id", nullable=true, onDelete="CASCADE")
     */
    private ?BasketComponent $component = null;

    /**
     * Columna GENERADA por la BBDD (no se escribe desde PHP): COALESCE(component_id, 0).
     * Existe solo para que los índices únicos muerdan también los intents de entrega
     * ENTERA (component_id NULL) — ver la NOTA de unicidad en el docblock de la clase. Es
     * de solo lectura (insertable/updatable=false); Doctrine la hidrata pero nunca la
     * incluye en INSERT/UPDATE (MySQL rechaza escribir una columna generada).
     *
     * @ORM\Column(type="integer", insertable=false, updatable=false, columnDefinition="INT AS (COALESCE(component_id, 0)) VIRTUAL")
     */
    private ?int $componentKey = null;

    /**
     * Motivo o nota opcional (la administración puede dejar comentario).
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $created = null;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $updated = null;

    /**
     * @param Partner              $partner   Socio.
     * @param Basket               $from      Semana origen (donde dejaría de recoger).
     * @param Basket|null          $to        Semana destino, o null = "no recoge".
     * @param BasketComponent|null $component Componente afectado, o null = toda la entrega.
     * @throws \InvalidArgumentException Si from y to apuntan al mismo Basket.
     */
    public function __construct(Partner $partner, Basket $from, ?Basket $to = null, ?BasketComponent $component = null)
    {
        if ($to !== null && $from->getId() !== null && $to->getId() !== null && $from->getId() === $to->getId()) {
            throw new \InvalidArgumentException('Un cambio puntual de viernes no puede tener el mismo Basket de origen y destino.');
        }
        $this->partner = $partner;
        $this->fromBasket = $from;
        $this->toBasket = $to;
        $this->component = $component;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function getFromBasket(): ?Basket
    {
        return $this->fromBasket;
    }

    public function getToBasket(): ?Basket
    {
        return $this->toBasket;
    }

    /**
     * Re-apunta el destino del cambio. Lo usa {@see \App\Service\Delivery\DeliveryShiftApplier::repoint()}
     * al mover una cesta ya movida a otro día sin pasar por su día de patrón (movimientos
     * encadenados / en ciclo). null la convertiría en "no recoge", pero el applier solo la
     * re-apunta a destinos reales.
     *
     * @param Basket|null $toBasket Nuevo destino del cambio.
     * @return self
     */
    public function setToBasket(?Basket $toBasket): self
    {
        if ($toBasket !== null && $this->accumulatedTo !== null) {
            throw new \LogicException('Un intent trasladado sumando no puede tener semana destino: su cesta ya está colocada como extra en accumulatedTo.');
        }
        $this->toBasket = $toBasket;
        return $this;
    }

    public function getAccumulatedTo(): ?Basket
    {
        return $this->accumulatedTo;
    }

    /**
     * Marca el intent como "trasladado sumando" a la semana $accumulatedTo (o lo
     * desmarca con null, al deshacer el traslado). Excluyente con `toBasket`: la
     * cesta está colocada como extra en otra semana, no movida a un destino.
     *
     * @param Basket|null $accumulatedTo Semana a cuya entrega se sumó la cesta.
     * @return self
     * @throws \LogicException Si el intent ya tiene semana destino.
     */
    public function setAccumulatedTo(?Basket $accumulatedTo): self
    {
        if ($accumulatedTo !== null && $this->toBasket !== null) {
            throw new \LogicException('Un intent con semana destino no puede además estar trasladado sumando.');
        }
        $this->accumulatedTo = $accumulatedTo;
        return $this;
    }

    public function getComponent(): ?BasketComponent
    {
        return $this->component;
    }

    public function setComponent(?BasketComponent $component): self
    {
        $this->component = $component;
        return $this;
    }

    /**
     * @return bool Si el intent afecta a toda la entrega (sin componente concreto).
     */
    public function isWholeDelivery(): bool
    {
        return $this->component === null;
    }

    /**
     * ¿El intent deja la semana origen sin entrega (sin semana destino)? Cierto
     * tanto para un "no recoge" como para un traslado sumando: en ambos casos el
     * generador NO debe materializar la cesta de patrón de esa semana. Para
     * distinguirlos, {@see self::isParked()} / {@see self::isAccumulated()}.
     *
     * @return bool
     */
    public function isSkip(): bool
    {
        return $this->toBasket === null;
    }

    /**
     * ¿La cesta está APARCADA, es decir pendiente y recolocable? Es el "no
     * recoge" propiamente dicho: el único caso que ocupa sitio en la papelera del
     * calendario. Un traslado sumando también deja la semana sin entrega, pero su
     * cesta ya está colocada en otro día y NO está pendiente.
     *
     * @return bool
     */
    public function isParked(): bool
    {
        return $this->toBasket === null && $this->accumulatedTo === null;
    }

    /**
     * @return bool Si la cesta de esta semana se trasladó SUMANDO a la entrega de otra.
     */
    public function isAccumulated(): bool
    {
        return $this->accumulatedTo !== null;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }
}
