<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Override puntual de NODO de recogida: una semana concreta, el socio recoge en un
 * grupo/nodo distinto a su nodo de casa. Es el tercer eje de desviación del reparto,
 * hermano de {@see PartnerDeliveryShift} (día / no-recoge / componente) y de
 * {@see PartnerBasketExtra} (cesta extra): mismo PRINCIPIO —un override que la proyección
 * aplica al dibujar las semanas futuras y que se cascadea a la piedra en las generadas—,
 * pero entidad propia para no colisionar con la semántica `to_basket=null` (= "no recoge")
 * de PartnerDeliveryShift. Ver docs/redesign/modelo-overrides-reparto.md.
 *
 * El patrón permanente del socio (su nodo de casa vía Partner.weekly_basket_group) NO se
 * toca: esto es de UNA semana (one_off). El grupo destino lleva implícito el nodo
 * (WeeklyBasketGroup.node), igual que cualquier WeeklyBasket.
 *
 * Unicidad: un solo override de nodo por (socio, semana). El audit trail de quién/cuándo
 * vive en PartnerEvent (NODE_CHANGE), no aquí; al cancelar, la fila se borra.
 *
 * @ORM\Table(name="partner_node_override", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_partner_basket_node_override", columns={"partner_id", "basket_id"})
 * }, indexes={
 *     @ORM\Index(name="idx_pno_basket", columns={"basket_id"}),
 *     @ORM\Index(name="idx_pno_group", columns={"target_group_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\PartnerNodeOverrideRepository")
 */
class PartnerNodeOverride
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
     * Semana (Basket-ciclo) a la que se limita el override.
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="basket_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Basket $basket = null;

    /**
     * Grupo de recogida destino esa semana (de un nodo distinto al de casa del socio).
     * Lleva el nodo implícito (WeeklyBasketGroup.node).
     * @ORM\ManyToOne(targetEntity="WeeklyBasketGroup")
     * @ORM\JoinColumn(name="target_group_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?WeeklyBasketGroup $targetGroup = null;

    /**
     * Motivo o nota opcional.
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
     * @param Partner           $partner     Socio.
     * @param Basket            $basket      Semana del override.
     * @param WeeklyBasketGroup $targetGroup Grupo/nodo destino esa semana.
     */
    public function __construct(Partner $partner, Basket $basket, WeeklyBasketGroup $targetGroup)
    {
        $this->partner = $partner;
        $this->basket = $basket;
        $this->targetGroup = $targetGroup;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function getTargetGroup(): ?WeeklyBasketGroup
    {
        return $this->targetGroup;
    }

    public function setTargetGroup(WeeklyBasketGroup $targetGroup): self
    {
        $this->targetGroup = $targetGroup;
        return $this;
    }

    /**
     * Nodo destino del override (derivado del grupo destino), o null si el grupo no tiene
     * nodo (datos legacy).
     *
     * @return Node|null
     */
    public function getTargetNode(): ?Node
    {
        return $this->targetGroup?->getNode();
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
