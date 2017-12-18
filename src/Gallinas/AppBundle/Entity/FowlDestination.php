<?php
/**
 * Determina qué se ha hecho con el animal en el caso de haberlo sacrificado (fowl_status).
 * Puede ser vendido, regalado (que incluiría compensado por deudas y se generaría a través de un regalo), o consumido por nosotrxs
 * User: paco
 * Date: 11/03/15
 * Time: 11:48
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\FowlDestination
 *
 * @ORM\Table(name="fowl_destination")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\FowlDestinationRepository")
 */
class FowlDestination
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
     * @var string $name
     * @Assert\NotBlank
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;


    /**
     * @ORM\OneToMany(targetEntity="Fowl", mappedBy="fowl_destination")
     */
    protected $fowls;
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->fowls = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return FowlDestination
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
     * Add fowls
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowls
     * @return FowlDestination
     */
    public function addFowl(\Gallinas\AppBundle\Entity\Fowl $fowls)
    {
        $this->fowls[] = $fowls;

        return $this;
    }

    /**
     * Remove fowls
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowls
     */
    public function removeFowl(\Gallinas\AppBundle\Entity\Fowl $fowls)
    {
        $this->fowls->removeElement($fowls);
    }

    /**
     * Get fowls
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getFowls()
    {
        return $this->fowls;
    }

    public function __toString()
    {
        return $this->getName();
    }
}
