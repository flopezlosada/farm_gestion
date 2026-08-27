<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una semana en la que un voluntario NO recoge su cesta de reparto, entera o
 * sólo un componente. Como la cesta del albergue es semanal y derivada (no se
 * materializa en weekly_basket, se dibuja al vuelo desde {@see Helper} + la
 * estancia), no hay un WeeklyBasket que marcar como saltado: el "no recoge" se
 * modela como esta excepción, una fila por (voluntario, fecha física, componente).
 *
 * `component` null = no recoge NADA esa semana (el caso histórico, el que marca
 * el voluntario desde su calendario). Con valor, sólo ese componente se cae y el
 * resto de la cesta sigue: es lo que necesita la retirada de huevos de un
 * reparto entero ({@see \App\Service\Delivery\NodeEggRescheduler}) — si la granja
 * no tiene huevos esa semana no los tiene para nadie, tampoco para el albergue,
 * pero la verdura sí se entrega.
 *
 * El histórico de skips SÍ se conserva (son datos persistidos), a diferencia de
 * la composición, que es config derivada. Ver
 * {@see \App\Service\Delivery\HelperDeliveryResolver}, que aplica unos y otros.
 *
 * NOTA unicidad: el unique NO puede ir sobre `component_id` directamente porque
 * MySQL trata los NULL como distintos y no impediría dos skips de cesta entera
 * el mismo día. Se resuelve igual que en {@see PartnerDeliveryShift}: con la
 * columna GENERADA `component_key` = COALESCE(component_id, 0). Estado ilegal
 * irrepresentable en BBDD, no sólo por convención del código.
 *
 * @ORM\Table(name="helper_basket_skip", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_helper_basket_skip", columns={"helper_id", "skip_date", "component_key"})
 * }, indexes={
 *     @ORM\Index(name="idx_hbs_component", columns={"component_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\HelperBasketSkipRepository")
 */
class HelperBasketSkip
{
    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Voluntario que salta la recogida. onDelete CASCADE: si se borra el
     * voluntario, sus skips se van con él.
     *
     * @var Helper
     * @ORM\ManyToOne(targetEntity="Helper")
     * @ORM\JoinColumn(name="helper_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private $helper;

    /**
     * Fecha física de entrega que se salta (el día real en que el nodo reparte,
     * no el viernes-ciclo). Se compara contra
     * {@see \App\Service\Delivery\NodeDeliveryDate::physicalDateFor}.
     *
     * @var \DateTimeImmutable
     * @ORM\Column(name="skip_date", type="date_immutable")
     */
    private $date;

    /**
     * Componente al que se limita el salto. NULL = no recoge nada esa semana
     * (caso histórico). Si no, sólo ese componente se cae y el resto sigue.
     *
     * @ORM\ManyToOne(targetEntity="BasketComponent")
     * @ORM\JoinColumn(name="component_id", referencedColumnName="id", nullable=true, onDelete="CASCADE")
     */
    private ?BasketComponent $component = null;

    /**
     * Columna GENERADA por la BBDD (no se escribe desde PHP): COALESCE(component_id, 0).
     * Existe sólo para que el índice único muerda también los skips de cesta ENTERA
     * (component_id NULL) — ver la NOTA de unicidad en el docblock de la clase. Es de
     * sólo lectura (insertable/updatable=false); Doctrine la hidrata pero nunca la
     * incluye en INSERT/UPDATE (MySQL rechaza escribir una columna generada).
     *
     * @ORM\Column(name="component_key", type="integer", insertable=false, updatable=false, columnDefinition="INT AS (COALESCE(component_id, 0)) VIRTUAL")
     */
    private ?int $componentKey = null;

    /**
     * @param Helper               $helper    Voluntario que salta la recogida.
     * @param \DateTimeImmutable   $date      Fecha física de entrega saltada.
     * @param BasketComponent|null $component Componente que se cae, o null para
     *                                        la cesta entera.
     */
    public function __construct(Helper $helper, \DateTimeImmutable $date, ?BasketComponent $component = null)
    {
        $this->helper = $helper;
        $this->date = $date;
        $this->component = $component;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Helper
     */
    public function getHelper(): Helper
    {
        return $this->helper;
    }

    /**
     * @return \DateTimeImmutable
     */
    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return BasketComponent|null Componente afectado, o null si es la cesta entera.
     */
    public function getComponent(): ?BasketComponent
    {
        return $this->component;
    }

    /**
     * @return bool Si el salto afecta a toda la cesta (sin componente concreto).
     */
    public function isWholeBasket(): bool
    {
        return $this->component === null;
    }
}
