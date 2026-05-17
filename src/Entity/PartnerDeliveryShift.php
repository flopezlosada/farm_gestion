<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Cambio puntual de viernes de un socio: en vez de recoger en el Basket
 * `from`, recoge en el Basket `to`. La pauta normal del socio (semanal,
 * quincenal, mensual con su grupo A/B implícito) sigue intacta antes y
 * después de este intercambio — solo se ve afectado el par from→to.
 *
 * Esta entidad es la fuente de verdad del intercambio. Crear o cancelar
 * un shift dispara, en la misma transacción, los cambios consecuentes
 * sobre WeeklyBasket (ver App\Service\Delivery\DeliveryShiftApplier).
 *
 * El audit trail de quién y cuándo se hizo el intercambio vive en
 * PartnerEvent (tipo WEEK_SWAP), no aquí. Si admin/socix cancela el
 * shift, la fila se borra; los PartnerEvent quedan en su sitio como
 * histórico inmutable.
 *
 * @ORM\Table(name="partner_delivery_shift", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_partner_from_basket", columns={"partner_id", "from_basket_id"}),
 *     @ORM\UniqueConstraint(name="uniq_partner_to_basket", columns={"partner_id", "to_basket_id"})
 * }, indexes={
 *     @ORM\Index(name="idx_from_basket", columns={"from_basket_id"}),
 *     @ORM\Index(name="idx_to_basket", columns={"to_basket_id"})
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
     * Viernes destino donde el socio recoge en su lugar.
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="to_basket_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Basket $toBasket = null;

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
     * @param Partner $partner Socio que cambia de viernes.
     * @param Basket  $from    Basket original (donde dejaría de recoger).
     * @param Basket  $to      Basket destino (donde recoge en su lugar).
     * @throws \InvalidArgumentException Si from y to apuntan al mismo Basket.
     */
    public function __construct(Partner $partner, Basket $from, Basket $to)
    {
        if ($from->getId() !== null && $to->getId() !== null && $from->getId() === $to->getId()) {
            throw new \InvalidArgumentException('Un cambio puntual de viernes no puede tener el mismo Basket de origen y destino.');
        }
        $this->partner = $partner;
        $this->fromBasket = $from;
        $this->toBasket = $to;
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
