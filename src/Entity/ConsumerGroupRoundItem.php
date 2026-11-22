<?php

namespace App\Entity;

use App\Service\ConsumerGroup\Markup;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Producto de una ronda concreta: enlaza un {@see ConsumerGroupProduct} del catálogo
 * del productor con una {@see ConsumerGroupRound}, fijando el PRECIO DE ESA RONDA
 * (el precio varía de ronda a ronda; el del catálogo es solo de referencia).
 *
 * Las socias apuntan cantidad contra este RoundItem ({@see ConsumerGroupOrderLine}),
 * no contra el producto del catálogo directamente. Una sola fila por (ronda, producto).
 *
 * @ORM\Table(name="consumer_group_round_item", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_cg_round_item_round_product", columns={"round_id", "product_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupRoundItemRepository")
 */
class ConsumerGroupRoundItem
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="ConsumerGroupRound", inversedBy="items")
     * @ORM\JoinColumn(name="round_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?ConsumerGroupRound $round = null;

    /**
     * Producto del catálogo. RESTRICT: un producto usado en alguna ronda no se puede
     * borrar (protege el histórico y la analítica por producto); se desactiva.
     * @ORM\ManyToOne(targetEntity="ConsumerGroupProduct")
     * @ORM\JoinColumn(name="product_id", nullable=false, onDelete="RESTRICT")
     */
    #[Assert\NotNull]
    private ?ConsumerGroupProduct $product = null;

    /**
     * Líneas de pedido de las socias sobre este item. Cascade remove +
     * orphanRemoval para que Doctrine sepa, al quitar el item de una ronda
     * ({@see ConsumerGroupRound::removeItem()}), que estas líneas se van con
     * él: sin este mapeo, el ORM no conoce esa relación (sólo existe el FK
     * `ON DELETE CASCADE` en BBDD) y se queda con líneas fantasma en el
     * UnitOfWork que revientan el siguiente flush() de la request.
     *
     * @ORM\OneToMany(targetEntity="ConsumerGroupOrderLine", mappedBy="roundItem", cascade={"remove"}, orphanRemoval=true)
     * @var Collection<int, ConsumerGroupOrderLine>
     */
    private Collection $lines;

    /**
     * Precio por unidad PARA ESTA RONDA, en euros. Decimal como string.
     *
     * 0 es un estado TRANSITORIO válido (recién sembrado desde el catálogo,
     * antes de que la comisión lo revise: {@see \App\Service\ConsumerGroup\RoundItemEditor::seedFromCatalog()}),
     * pero no se puede GUARDAR un producto incluido en la ronda con precio 0
     * —eso lo exige el controller al validar el precio explícitamente en el
     * guardado de "Productos del pedido", no aquí en el flush del alta—.
     * @ORM\Column(type="decimal", precision=8, scale=2)
     */
    #[Assert\NotNull]
    #[Assert\Positive(message: 'Pon un precio: no puede quedarse a 0.')]
    private string $price = '0';

    /**
     * Precio del productor por unidad. null = sin desglosar (rondas anteriores al
     * desglose). El precio final es {@see $price}: producerPrice + incremento.
     * @ORM\Column(name="producer_price", type="decimal", precision=8, scale=2, nullable=true)
     */
    #[Assert\Positive]
    private ?string $producerPrice = null;

    /**
     * Incremento PROPIO de este producto; si falta, vale el del pedido
     * ({@see ConsumerGroupRound::getMarkupType()}).
     * @ORM\Column(name="markup_type", type="string", length=10, nullable=true, enumType="App\Entity\MarkupType")
     */
    private ?MarkupType $markupType = null;

    /**
     * Valor del incremento propio, en la unidad de {@see $markupType}.
     * @ORM\Column(name="markup_value", type="decimal", precision=8, scale=2, nullable=true)
     */
    #[Assert\PositiveOrZero]
    private ?string $markupValue = null;

    /**
     * true cuando cambió el incremento del pedido y este producto, que tiene el suyo,
     * aún no se ha revisado. Mientras haya alguno así el pedido no se puede abrir.
     * @ORM\Column(name="markup_pending_review", type="boolean", options={"default": false})
     */
    private bool $markupPendingReview = false;

    /**
     * Lo que la ASOCIACIÓN pide de este producto para el local, además de lo que
     * piden las socias. Decimal como string, al mismo precio de ronda.
     *
     * No tiene socia detrás a propósito: se suma al pedido que se le pasa al
     * productor, pero no es de nadie —no se cobra, no se reparte y no cuenta como
     * participante—. La alternativa era apuntar a «la asociación» como si fuera
     * una socia más, y entonces habría que acordarse de excluirla en el recuento,
     * en los avisos, en los pagos, en las estadísticas y en la hoja de reparto.
     *
     * @ORM\Column(name="association_quantity", type="decimal", precision=8, scale=2, options={"default": "0"})
     */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private string $associationQuantity = '0';

    /**
     * Orden de presentación dentro de la ronda.
     * @ORM\Column(type="smallint")
     */
    private int $sortOrder = 0;

    public function __construct(?ConsumerGroupRound $round = null, ?ConsumerGroupProduct $product = null, string $price = '0')
    {
        $this->round = $round;
        $this->product = $product;
        $this->price = $price;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): ?ConsumerGroupRound
    {
        return $this->round;
    }

    public function setRound(?ConsumerGroupRound $round): self
    {
        $this->round = $round;
        return $this;
    }

    /**
     * ¿Es de un productor autogestionado? Él fija su precio y no lleva incremento
     * de gestión. Se pregunta al producto, no a la ronda: el productor de cada
     * línea es el de su producto.
     */
    public function isSelfManaged(): bool
    {
        return $this->product?->getProducer()?->isSelfManaged() ?? false;
    }

    public function getProduct(): ?ConsumerGroupProduct
    {
        return $this->product;
    }

    public function setProduct(?ConsumerGroupProduct $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    /**
     * Líneas de pedido de las socias sobre este producto.
     *
     * @return Collection<int, ConsumerGroupOrderLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /**
     * ¿Alguna socia ha apuntado cantidad (> 0) de este producto? Las líneas a 0 no
     * cuentan: no hay nada que la socia haya visto y aceptado. Con cantidad apuntada
     * el precio final queda fijado.
     */
    public function hasOrderedQuantity(): bool
    {
        return $this->lines->exists(static fn (int $i, ConsumerGroupOrderLine $line): bool => (float) $line->getQuantity() > 0);
    }

    /**
     * Precio unitario que cobra el productor: el suyo si está desglosado y, si no, el
     * final (los pedidos sin desglosar o de un productor autogestionado no llevan
     * incremento, así que es el mismo). Es el que va en lo que se le pasa al productor.
     */
    public function getPriceForProducer(): string
    {
        return $this->producerPrice ?? $this->price;
    }

    public function getProducerPrice(): ?string
    {
        return $this->producerPrice;
    }

    public function setProducerPrice(?string $producerPrice): self
    {
        $this->producerPrice = $producerPrice;
        return $this;
    }

    public function getMarkupType(): ?MarkupType
    {
        return $this->markupType;
    }

    public function getMarkupValue(): ?string
    {
        return $this->markupValue;
    }

    /**
     * Fija el incremento PROPIO del producto; ambos null lo quitan y el producto
     * vuelve a heredar el del pedido.
     */
    public function setOwnMarkup(?MarkupType $type, ?string $value): self
    {
        $this->markupType = $type;
        $this->markupValue = $value;
        return $this;
    }

    /**
     * Incremento propio del producto, o null si hereda el del pedido.
     */
    public function getOwnMarkup(): ?Markup
    {
        return Markup::fromStored($this->markupType, $this->markupValue);
    }

    /**
     * Incremento que de verdad se aplica: el propio si lo tiene, si no el del pedido.
     */
    public function getEffectiveMarkup(): ?Markup
    {
        return $this->getOwnMarkup() ?? $this->round?->getMarkup();
    }

    public function isMarkupPendingReview(): bool
    {
        return $this->markupPendingReview;
    }

    public function setMarkupPendingReview(bool $markupPendingReview): self
    {
        $this->markupPendingReview = $markupPendingReview;
        return $this;
    }

    /**
     * Cantidad que pide la asociación para el local ({@see $associationQuantity}).
     */
    public function getAssociationQuantity(): string
    {
        return $this->associationQuantity;
    }

    public function setAssociationQuantity(string $associationQuantity): self
    {
        $this->associationQuantity = $associationQuantity;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    /**
     * Nombre del producto (del catálogo), para pintar sin navegar la relación.
     */
    public function getName(): string
    {
        return $this->product?->getName() ?? '';
    }

    /**
     * Unidad del producto (del catálogo), como texto: para pintar sin que
     * cada plantilla tenga que navegar product->unit->name.
     */
    public function getUnit(): string
    {
        return $this->product?->getUnit()?->getName() ?? '';
    }
}
