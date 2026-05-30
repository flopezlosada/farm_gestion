<?php
/**
 * Una línea de una entrega: qué componente (verdura, huevos…) y cuánto lleva
 * un WeeklyBasket concreto. Es la materialización de la composición de la
 * entrega: dato GUARDADO, no derivado de una fórmula. Cuando una entrega se
 * mueve (PartnerDeliveryShift) o se salta, sus ítems viajan con ella por la FK.
 *
 * Una entrega tiene 0..N ítems: verdura sola, huevos solos, o ambos.
 *
 * Modelado en el rediseño del calendario de recogida (2026-05-29). Etapa 1:
 * se estampa reflejando el comportamiento actual del generador; todavía no lo
 * lee nadie.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * App\Entity\WeeklyBasketItem
 *
 * @ORM\Table(name="weekly_basket_item", indexes={
 *     @ORM\Index(name="idx_wbi_weekly_basket", columns={"weekly_basket_id"}),
 *     @ORM\Index(name="idx_wbi_component", columns={"basket_component_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\WeeklyBasketItemRepository")
 */
class WeeklyBasketItem
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * Entrega a la que pertenece esta línea. onDelete=CASCADE: si se borra la
     * entrega (p. ej. al revertir un alta), sus ítems desaparecen con ella.
     *
     * @ORM\ManyToOne(targetEntity="WeeklyBasket")
     * @ORM\JoinColumn(name="weekly_basket_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?WeeklyBasket $weeklyBasket = null;

    /**
     * Tipo de componente de esta línea (verdura, huevos…).
     *
     * @ORM\ManyToOne(targetEntity="BasketComponent")
     * @ORM\JoinColumn(name="basket_component_id", referencedColumnName="id", nullable=false)
     */
    #[Assert\NotNull]
    private ?BasketComponent $basketComponent = null;

    /**
     * Cantidad de este componente en la entrega, expresada en la UNIDAD NATURAL
     * del componente: verdura en cestas (1, 2…), huevos en docenas (0.5, 1,
     * 1.5, 2). Decimal para soportar medias docenas y futuras unidades (kg…).
     * La etiqueta que ve el socio ("media docena") es cosa de la capa de vista,
     * no del almacén.
     *
     * Doctrine mapea decimal a string en PHP (igual que transport_price).
     *
     * @ORM\Column(type="decimal", precision=6, scale=2)
     */
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    private string $amount = '0';

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return WeeklyBasket|null
     */
    public function getWeeklyBasket(): ?WeeklyBasket
    {
        return $this->weeklyBasket;
    }

    /**
     * @param WeeklyBasket|null $weeklyBasket
     * @return self
     */
    public function setWeeklyBasket(?WeeklyBasket $weeklyBasket): self
    {
        $this->weeklyBasket = $weeklyBasket;
        return $this;
    }

    /**
     * @return BasketComponent|null
     */
    public function getBasketComponent(): ?BasketComponent
    {
        return $this->basketComponent;
    }

    /**
     * @param BasketComponent|null $basketComponent
     * @return self
     */
    public function setBasketComponent(?BasketComponent $basketComponent): self
    {
        $this->basketComponent = $basketComponent;
        return $this;
    }

    /**
     * @return string Cantidad decimal en la unidad natural del componente.
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @param string $amount Cantidad decimal en la unidad natural del componente.
     * @return self
     */
    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }
}
