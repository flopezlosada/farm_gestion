<?php
/**
 * Recogida de residuos para la pila de compost
 * User: paco
 * Date: 22/10/18
 * Time: 14:58
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\CompostCollection
 *
 * @ORM\Table(name="compost_collection")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CompostCollectionRepository")
 */
class CompostCollection
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
     * Fecha de recogida
     * @Assert\NotBlank
     * @Assert\Date()
     * @var string $collect_date
     * @ORM\Column(name="collect_date", type="date")
     */
    private $collect_date;

    /**
     * Semana (dentro de cada año) a la que se refiere esta recogida. Se calcula automáticamente, Es para las estadísticas semanales
     * @var smallint $week
     * @ORM\Column(name="week", type="integer")
     */
    private $week;

    /**
     * Cantidad de kilos
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $amount
     * @ORM\Column(name="amount",type="integer")
     */
    private $amount;

    /**
     * punto de recogida
     * @Assert\NotBlank
     * @var smallint $compost_collection_point
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\CompostCollectionPoint", inversedBy="compost_collections")
     */
    private $compost_collection_point;


    /**
     * porcentaje de impropios
     * @Assert\Type(type="integer", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThanOrEqual(
     *     value = 0
     * )
     * @Assert\LessThanOrEqual(
     *     value = 100
     * )
     * @var smallint $improper_percentage
     * @ORM\Column(name="improper_percentage",type="integer")
     */
    private $improper_percentage;

    /**
     * punto de recogida
     * @var smallint $compost_pile
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\CompostPile", inversedBy="compost_collections")
     */
    private $compost_pile;

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
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set collect_date
     *
     * @param \DateTime $collectDate
     * @return CompostCollection
     */
    public function setCollectDate($collectDate)
    {
        $this->collect_date = $collectDate;

        return $this;
    }

    /**
     * Get collect_date
     *
     * @return \DateTime
     */
    public function getCollectDate()
    {
        return $this->collect_date;
    }

    /**
     * Set week
     *
     * @param integer $week
     * @return CompostCollection
     */
    public function setWeek($week)
    {
        $this->week = $week;

        return $this;
    }

    /**
     * Get week
     *
     * @return integer
     */
    public function getWeek()
    {
        return $this->week;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return CompostCollection
     */
    public function setAmount($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Get amount
     *
     * @return integer
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * Set compost_collection_point
     *
     * @param \Gallinas\AppBundle\Entity\CompostCollectionPoint $compostCollectionPoint
     * @return CompostCollection
     */
    public function setCompostCollectionPoint(\Gallinas\AppBundle\Entity\CompostCollectionPoint $compostCollectionPoint = null)
    {
        $this->compost_collection_point = $compostCollectionPoint;

        return $this;
    }

    /**
     * Get compost_collection_point
     *
     * @return \Gallinas\AppBundle\Entity\CompostCollectionPoint
     */
    public function getCompostCollectionPoint()
    {
        return $this->compost_collection_point;
    }

    /**
     * Set compost_pile
     *
     * @param \Gallinas\AppBundle\Entity\CompostPile $compostPile
     * @return CompostCollection
     */
    public function setCompostPile(\Gallinas\AppBundle\Entity\CompostPile $compostPile = null)
    {
        $this->compost_pile = $compostPile;

        return $this;
    }

    /**
     * Get compost_pile
     *
     * @return \Gallinas\AppBundle\Entity\CompostPile
     */
    public function getCompostPile()
    {
        return $this->compost_pile;
    }

    /**
     * Set improper_percentage
     *
     * @param integer $improperPercentage
     * @return CompostCollection
     */
    public function setImproperPercentage($improperPercentage)
    {
        $this->improper_percentage = $improperPercentage;

        return $this;
    }

    /**
     * Get improper_percentage
     *
     * @return integer 
     */
    public function getImproperPercentage()
    {
        return $this->improper_percentage;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return CompostCollection
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
     * @return CompostCollection
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


    public function getDate()
    {
        return $this->getCollectDate();
    }
}
