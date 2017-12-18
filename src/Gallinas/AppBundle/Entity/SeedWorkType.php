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
 * Gallinas\AppBundle\Entity\SeedWorkType
 *
 * @ORM\Table(name="seed_work_type")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\SeedWorkTypeRepository")
 *
 */

class SeedWorkType
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
     * @ORM\OneToMany(targetEntity="SeedWork", mappedBy="seed_work_type")
     */
    protected $seed_works;

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
        $this->seed_works = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return SeedWorkType
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
     * @return SeedWorkType
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
     * @return SeedWorkType
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
     * Add seed_works
     *
     * @param \Gallinas\AppBundle\Entity\Seedwork $seedWorks
     * @return SeedWorkType
     */
    public function addSeedWork(\Gallinas\AppBundle\Entity\Seedwork $seedWorks)
    {
        $this->seed_works[] = $seedWorks;

        return $this;
    }

    /**
     * Remove seed_works
     *
     * @param \Gallinas\AppBundle\Entity\Seedwork $seedWorks
     */
    public function removeSeedWork(\Gallinas\AppBundle\Entity\Seedwork $seedWorks)
    {
        $this->seed_works->removeElement($seedWorks);
    }

    /**
     * Get seed_works
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getSeedWorks()
    {
        return $this->seed_works;
    }

    public function __toString()
    {
        return $this->getTitle();
    }
}
