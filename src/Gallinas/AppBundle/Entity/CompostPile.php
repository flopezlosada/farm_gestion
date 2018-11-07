<?php
/**
 * Created by PhpStorm.
 * User: paco
 * Date: 22/10/18
 * Time: 14:53
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\CompostPile
 *
 * @ORM\Table(name="compost_pile")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CompostPileRepository")
 *
 */
class CompostPile
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
     * Fecha de compra del pedido
     * @Assert\NotBlank
     * @Assert\Date()
     * @var string $start_date
     * @ORM\Column(name="start_date", type="date")
     */
    private $start_date;

    /**
     * Fecha de compra del pedido
     * @Assert\Date()
     * @var string $end_date
     * @ORM\Column(name="end_date", type="date", nullable=true)
     */
    private $end_date;

    /**
     * @ORM\OneToMany(targetEntity="CompostCollection", mappedBy="compost_pile")
     */
    protected $compost_collections;

    /**
     * @var \DateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime $updated
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
        $this->compost_collections = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set start_date
     *
     * @param \DateTime $startDate
     * @return CompostPile
     */
    public function setStartDate($startDate)
    {
        $this->start_date = $startDate;

        return $this;
    }

    /**
     * Get start_date
     *
     * @return \DateTime
     */
    public function getStartDate()
    {
        return $this->start_date;
    }

    /**
     * Set end_date
     *
     * @param \DateTime $endDate
     * @return CompostPile
     */
    public function setEndDate($endDate)
    {
        $this->end_date = $endDate;

        return $this;
    }

    /**
     * Get end_date
     *
     * @return \DateTime
     */
    public function getEndDate()
    {
        return $this->end_date;
    }

    /**
     * Add compost_collections
     *
     * @param \Gallinas\AppBundle\Entity\CompostCollection $compostCollections
     * @return CompostPile
     */
    public function addCompostCollection(\Gallinas\AppBundle\Entity\CompostCollection $compostCollections)
    {
        $this->compost_collections[] = $compostCollections;

        return $this;
    }

    /**
     * Remove compost_collections
     *
     * @param \Gallinas\AppBundle\Entity\CompostCollection $compostCollections
     */
    public function removeCompostCollection(\Gallinas\AppBundle\Entity\CompostCollection $compostCollections)
    {
        $this->compost_collections->removeElement($compostCollections);
    }

    /**
     * Get compost_collections
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCompostCollections()
    {
        return $this->compost_collections;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return CompostPile
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
     * @return CompostPile
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

    public function getAmount()
    {
        $amount = 0;

        foreach ($this->getCompostCollections() as $collection) {
            $amount += $collection->getAmount();
        }

        return $amount;
    }
}
