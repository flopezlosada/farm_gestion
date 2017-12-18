<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 8/11/17
 * Time: 18:58
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\CulturalWorkType
 *
 * @ORM\Table(name="cultural_work_type")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CulturalWorkTypeRepository")
 *
 */

class CulturalWorkType
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
     * @ORM\OneToMany(targetEntity="CulturalWork", mappedBy="cultural_work_type")
     */
    protected $cultural_works;

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
        $this->cultural_works = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return CulturalWorkType
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
     * @return CulturalWorkType
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
     * @return CulturalWorkType
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
     * Add cultural_works
     *
     * @param \Gallinas\AppBundle\Entity\Culturalwork $culturalWorks
     * @return CulturalWorkType
     */
    public function addCulturalWork(\Gallinas\AppBundle\Entity\Culturalwork $culturalWorks)
    {
        $this->cultural_works[] = $culturalWorks;

        return $this;
    }

    /**
     * Remove cultural_works
     *
     * @param \Gallinas\AppBundle\Entity\Culturalwork $culturalWorks
     */
    public function removeCulturalWork(\Gallinas\AppBundle\Entity\Culturalwork $culturalWorks)
    {
        $this->cultural_works->removeElement($culturalWorks);
    }

    /**
     * Get cultural_works
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getCulturalWorks()
    {
        return $this->cultural_works;
    }

    public function __toString()
    {
        return $this->getTitle();
    }
}
