<?php
/**
 * Created by diphda.net.
 * Bancales
 * User: paco
 * Date: 10/12/15
 * Time: 22:05
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Patch
 *
 * @ORM\Table(name="patch")
 * @ORM\Entity(repositoryClass="App\Repository\PatchRepository")
 */
class Patch
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
     * Devuelve una lista de cultivos que se han establecido en este bancal
     * @ORM\ManyToMany(targetEntity="CropWorking", mappedBy="patchs")
     */
    private $crop_workings;

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
        $this->crop_workings = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Patch
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
     * @return Patch
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
     * Add cropWorking
     *
     * @param \App\Entity\CropWorking $cropWorking
     *
     * @return Patch
     */
    public function addCropWorking(\App\Entity\CropWorking $cropWorking)
    {
        $this->crop_workings[] = $cropWorking;

        return $this;
    }

    /**
     * Remove cropWorking
     *
     * @param \App\Entity\CropWorking $cropWorking
     */
    public function removeCropWorking(\App\Entity\CropWorking $cropWorking)
    {
        $this->crop_workings->removeElement($cropWorking);
    }

    /**
     * Get cropWorkings
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCropWorkings()
    {
        return $this->crop_workings;
    }

    public function __toString()
    {
        return "Bancal ".$this->getId();
    }
}
