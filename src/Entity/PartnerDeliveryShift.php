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
 *
 * Combinaciones: mover toda la entrega (component null, to≠null) — el caso
 * histórico; mover un componente (component≠null, to≠null); no recoger
 * (component null, to null); quitar un componente esa semana (component≠null,
 * to null).
 *
 * Esta entidad es la fuente de verdad del intent. Crear o cancelar uno dispara,
 * en la misma transacción, los cambios consecuentes sobre WeeklyBasket (ver
 * App\Service\Delivery\DeliveryShiftApplier).
 *
 * El audit trail de quién y cuándo vive en PartnerEvent (tipo WEEK_SWAP), no
 * aquí. Si admin/socix lo cancela, la fila se borra; los PartnerEvent quedan
 * como histórico inmutable.
 *
 * NOTA unicidad: el unique incluye `component_id`, pero MySQL trata los NULL
 * como distintos, así que NO impide dos intents de entrega-entera (component
 * null) con el mismo `from`. Esa unicidad la garantiza el código (el applier
 * comprueba findOutgoing/findIncoming antes de crear).
 *
 * @ORM\Table(name="partner_delivery_shift", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_partner_from_basket", columns={"partner_id", "from_basket_id", "component_id"}),
 *     @ORM\UniqueConstraint(name="uniq_partner_to_basket", columns={"partner_id", "to_basket_id", "component_id"})
 * }, indexes={
 *     @ORM\Index(name="idx_from_basket", columns={"from_basket_id"}),
 *     @ORM\Index(name="idx_to_basket", columns={"to_basket_id"}),
 *     @ORM\Index(name="idx_pds_component", columns={"component_id"})
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
     * Componente al que se limita el intent. NULL = toda la entrega (caso
     * histórico). Si no, verdura u huevos: el intent solo afecta a ese
     * componente y los demás siguen su propio calendario (caso SANTOS).
     * @ORM\ManyToOne(targetEntity="BasketComponent")
     * @ORM\JoinColumn(name="component_id", nullable=true, onDelete="CASCADE")
     */
    private ?BasketComponent $component = null;

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
     * @return bool Si el intent es "no recoger" (sin semana destino).
     */
    public function isSkip(): bool
    {
        return $this->toBasket === null;
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
