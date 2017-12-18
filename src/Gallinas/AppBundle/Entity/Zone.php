<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 8/11/17
 * Time: 18:43
 * Zonas de cultivo. Se nombran con la notación siguiente: Zona 8. Un cultivo puede estar en varias zonas
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Zone
 *
 * @ORM\Table(name="zone")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\ZoneRepository")
 */
class Zone
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
     * @ORM\OneToMany(targetEntity="Sector", mappedBy="zone")
     */
    protected $sectors;


    /**
     * @Assert\NotBlank
     * @var string name
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;


    public function __toString()
    {
        return $this->getName();
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
     * Set name
     *
     * @param string $name
     * @return Zone
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string 
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->sectors = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Add sectors
     *
     * @param \Gallinas\AppBundle\Entity\Sector $sectors
     * @return Zone
     */
    public function addSector(\Gallinas\AppBundle\Entity\Sector $sectors)
    {
        $this->sectors[] = $sectors;

        return $this;
    }

    /**
     * Remove sectors
     *
     * @param \Gallinas\AppBundle\Entity\Sector $sectors
     */
    public function removeSector(\Gallinas\AppBundle\Entity\Sector $sectors)
    {
        $this->sectors->removeElement($sectors);
    }

    /**
     * Get sectors
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getSectors()
    {
        return $this->sectors;
    }
}
