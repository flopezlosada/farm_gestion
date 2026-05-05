<?php
/**
 * Created by diphda.net.
 * Sirve para distinguir los distintos tipos de sucesos que sufren las cestas
 *  -Recogida
 *  -No recogida
 *  -Aplazada
 * User: paco
 * Date: 1/12/15
 * Time: 21:52
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\WeeklyBasketStatus
 *
 * @ORM\Table(name="weekly_basket_status")
 * @ORM\Entity(repositoryClass="App\Repository\WeeklyBasketStatusRepository")
 */
class WeeklyBasketStatus
{
    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\OneToMany(targetEntity="WeeklyBasket", mappedBy="weekly_basket_status")
     */
    protected $weekly_baskets;

    /**
     * @var string $title
     * @Assert\NotBlank
     * @ORM\Column(name="title", type="string", length=255)
     */
    private $title;

    /**
     * @var dateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var dateTime $updated
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->weekly_baskets = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set title
     *
     * @param string $title
     *
     * @return WeeklyBasketStatus
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return WeeklyBasketStatus
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set updated
     *
     * @param \DateTime $updated
     *
     * @return WeeklyBasketStatus
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated
     *
     * @return \DateTime
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add weekly_basket
     *
     * @param \App\Entity\WeeklyBasket $weekly_basket
     *
     * @return WeeklyBasketStatus
     */
    public function addWeeklyBasket(\App\Entity\WeeklyBasket $weekly_basket)
    {
        $this->weekly_baskets[] = $weekly_basket;

        return $this;
    }

    /**
     * Remove weekly_basket
     *
     * @param \App\Entity\WeeklyBasket $weekly_basket
     */
    public function removeWeeklyBasket(\App\Entity\WeeklyBasket $weekly_basket)
    {
        $this->weekly_baskets->removeElement($weekly_basket);
    }

    /**
     * Get weekly_baskets
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getWeeklyBaskets()
    {
        return $this->weekly_baskets;
    }

    public function __toString()
    {
        return $this->getTitle();
    }
}
