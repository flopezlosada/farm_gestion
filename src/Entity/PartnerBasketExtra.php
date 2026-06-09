<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Override puntual de CESTA EXTRA: una semana concreta, el socio lleva una cantidad
 * ADICIONAL de un componente (p. ej. +1 cesta de verdura, +½ docena de huevos) sobre lo
 * que le tocaría por su patrón. Es el cuarto eje de desviación del reparto, hermano de
 * {@see PartnerDeliveryShift} (día / no-recoge / componente) y de {@see PartnerNodeOverride}
 * (nodo): mismo PRINCIPIO —un override que la proyección suma al dibujar las semanas futuras
 * y que se cascadea a la piedra en las generadas—. Ver docs/redesign/modelo-overrides-reparto.md.
 *
 * Es un DELTA aditivo, no un total: `amount` es lo que se SUMA a la composición de esa
 * semana (la base sale del patrón). Una fila por (socio, semana, componente): así "+1 cesta
 * y +½ docena" son dos filas. El patrón permanente del socio NO se toca: esto es de UNA
 * semana (one_off).
 *
 * Unicidad: un solo extra por (socio, semana, componente). El audit trail de quién/cuándo y
 * el motodo vive en PartnerEvent (BASKET_EXTRA); al cancelar, la fila se borra.
 *
 * @ORM\Table(name="partner_basket_extra", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_partner_basket_component_extra", columns={"partner_id", "basket_id", "basket_component_id"})
 * }, indexes={
 *     @ORM\Index(name="idx_pbe_basket", columns={"basket_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\PartnerBasketExtraRepository")
 */
class PartnerBasketExtra
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
     * Semana (Basket-ciclo) a la que se limita el extra.
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="basket_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Basket $basket = null;

    /**
     * Componente al que se añade cantidad (verdura, huevos…).
     * @ORM\ManyToOne(targetEntity="BasketComponent")
     * @ORM\JoinColumn(name="basket_component_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?BasketComponent $component = null;

    /**
     * Cantidad ADICIONAL (delta) en la unidad natural del componente, mismo tipo que
     * {@see WeeklyBasketItem::$amount} para no introducir drift de decimales.
     * @ORM\Column(type="decimal", precision=6, scale=2)
     */
    private string $amount = '0';

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
     * @param Partner         $partner   Socio.
     * @param Basket          $basket    Semana del extra.
     * @param BasketComponent $component Componente al que se añade cantidad.
     * @param string          $amount    Cantidad adicional (decimal como string, p. ej. "1.00").
     */
    public function __construct(Partner $partner, Basket $basket, BasketComponent $component, string $amount)
    {
        $this->partner = $partner;
        $this->basket = $basket;
        $this->component = $component;
        $this->amount = $amount;
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

    public function getComponent(): ?BasketComponent
    {
        return $this->component;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
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
