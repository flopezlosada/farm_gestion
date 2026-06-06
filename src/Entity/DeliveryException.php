<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Excepción al calendario de reparto, anclada a un ciclo (Basket) y
 * opcionalmente a un nodo concreto.
 *
 * Semántica:
 *   - `basket`: el ciclo semanal afectado. Recuerda que un Basket es la
 *     semana operativa global; cada nodo deriva su día físico de reparto
 *     de ese ciclo vía NodeDeliveryDate.
 *   - `node` nullable: si es null la excepción aplica a TODOS los nodos
 *     (cierre general: Navidad, agosto, puente largo); con valor aplica
 *     sólo a ese nodo (un festivo de viernes afecta a Torremocha pero no
 *     a Cascorro, que reparte en miércoles).
 *   - `shiftedDate` null = ese ciclo no hay reparto (cancelado); con fecha
 *     el reparto físico se traslada a ese día (típicamente el día hábil
 *     anterior si el día normal es festivo).
 *
 * La ausencia de fila para un (ciclo, nodo) equivale a "reparto normal".
 * No es necesario crear una DeliveryException para cada semana.
 *
 * Reescrita en sub-fase 8.8d (2026-05-27): antes anclaba a `friday_date`
 * global y única. Con la entrada de nodos en días distintos (Madrid en
 * miércoles) una fecha suelta dejó de identificar el reparto; ahora se
 * ancla a (basket, node) para poder cancelar/mover el reparto de un nodo
 * sin afectar a los demás, y planificarlo por adelantado.
 *
 * @ORM\Table(name="delivery_exception", indexes={
 *     @ORM\Index(name="idx_de_basket", columns={"basket_id"}),
 *     @ORM\Index(name="idx_de_node", columns={"node_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\DeliveryExceptionRepository")
 */
class DeliveryException
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Ciclo semanal afectado.
     * @ORM\ManyToOne(targetEntity="Basket")
     * @ORM\JoinColumn(name="basket_id", nullable=false)
     */
    #[Assert\NotNull]
    private ?Basket $basket = null;

    /**
     * Nodo al que aplica la excepción. Null = aplica a todos los nodos.
     * @ORM\ManyToOne(targetEntity="Node")
     * @ORM\JoinColumn(name="node_id", nullable=true, onDelete="CASCADE")
     */
    private ?Node $node = null;

    /**
     * Día al que se traslada el reparto. Si es null, no hay reparto ese
     * ciclo. Si tiene valor, el reparto físico cae en esa fecha.
     * @ORM\Column(type="date", nullable=true)
     */
    private ?\DateTimeInterface $shiftedDate = null;

    /**
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

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Basket|null Ciclo semanal afectado.
     */
    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    /**
     * @param Basket $basket Ciclo semanal afectado.
     * @return self
     */
    public function setBasket(Basket $basket): self
    {
        $this->basket = $basket;
        return $this;
    }

    /**
     * @return Node|null Nodo afectado, o null si la excepción es global.
     */
    public function getNode(): ?Node
    {
        return $this->node;
    }

    /**
     * @param Node|null $node Nodo afectado, o null para excepción global.
     * @return self
     */
    public function setNode(?Node $node): self
    {
        $this->node = $node;
        return $this;
    }

    public function getShiftedDate(): ?\DateTimeInterface
    {
        return $this->shiftedDate;
    }

    public function setShiftedDate(?\DateTimeInterface $shiftedDate): self
    {
        $this->shiftedDate = $shiftedDate;
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

    /**
     * Indica si la excepción cancela el reparto (no hay entrega ese ciclo).
     *
     * @return bool True si no se reparte; false si se traslada a otra fecha.
     */
    public function isCancelled(): bool
    {
        return $this->shiftedDate === null;
    }

    /**
     * Indica si la excepción traslada el reparto a otra fecha.
     *
     * La fecha física original depende del nodo y la resuelve
     * NodeDeliveryDate, por lo que aquí basta con saber si hay una fecha
     * destino registrada. El CRUD es responsable de no registrar un
     * traslado al mismo día que el reparto normal.
     *
     * @return bool True si el reparto se mueve a `shiftedDate`.
     */
    public function isShifted(): bool
    {
        return $this->shiftedDate !== null;
    }

    /**
     * Indica si la excepción aplica a todos los nodos (cierre general).
     *
     * @return bool True si no está acotada a un nodo concreto.
     */
    public function isGlobal(): bool
    {
        return $this->node === null;
    }
}
