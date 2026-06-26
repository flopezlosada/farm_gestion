<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una semana en la que un voluntario NO recoge su cesta de reparto. Como la
 * cesta del albergue es semanal y derivada (no se materializa en weekly_basket,
 * se dibuja al vuelo desde {@see Helper} + la estancia), no hay un WeeklyBasket
 * que marcar como saltado: el "no recoge" se modela como esta excepción, una
 * fila por (voluntario, fecha física de entrega saltada).
 *
 * El histórico de skips SÍ se conserva (son datos persistidos), a diferencia de
 * la composición, que es config derivada. Ver
 * {@see \App\Service\Delivery\HelperDeliveryResolver}, que excluye a los
 * voluntarios con skip en la fecha del reparto.
 *
 * @ORM\Table(name="helper_basket_skip", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_helper_basket_skip", columns={"helper_id", "skip_date"})
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
     * @param Helper             $helper Voluntario que salta la recogida.
     * @param \DateTimeImmutable $date   Fecha física de entrega saltada.
     */
    public function __construct(Helper $helper, \DateTimeImmutable $date)
    {
        $this->helper = $helper;
        $this->date = $date;
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
}
