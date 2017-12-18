<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 10/12/15
 * Time: 21:58
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Harvest
 *
 * @ORM\Table(name="harvest")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\HarvestRepository")
 */
class Harvest
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
     * @var smallint $crop_working
     * @ORM\ManyToOne(targetEntity="CropWorking", inversedBy="harvests")
     */
    private $crop_working;

    /**
     * @Assert\NotBlank
     * @Assert\Date()
     * @var string $harvest_date
     * @ORM\Column(name="harvest_date", type="date")
     */
    private $harvest_date;

    /**
     * Kilos recogidos
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $amount
     * @ORM\Column(name="amount", type="integer")
     */
    private $amount;

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
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Harvest
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
     * @return Harvest
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
     * Set harvestDate
     *
     * @param \DateTime $harvestDate
     *
     * @return Harvest
     */
    public function setHarvestDate($harvestDate)
    {
        $this->harvest_date = $harvestDate;

        return $this;
    }

    /**
     * Get harvestDate
     *
     * @return \DateTime
     */
    public function getHarvestDate()
    {
        return $this->harvest_date;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     *
     * @return Harvest
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
     * Set cropWorking
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorking
     *
     * @return Harvest
     */
    public function setCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorking = null)
    {
        $this->crop_working = $cropWorking;

        return $this;
    }

    /**
     * Get cropWorking
     *
     * @return \Gallinas\AppBundle\Entity\CropWorking
     */
    public function getCropWorking()
    {
        return $this->crop_working;
    }

    public function getDate()
    {
        return $this->getHarvestDate();
    }

}
