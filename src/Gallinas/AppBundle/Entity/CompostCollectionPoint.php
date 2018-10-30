<?php
/**
 * Created by PhpStorm.
 * User: paco
 * Date: 22/10/18
 * Time: 15:00
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\CompostCollectionPoint
 *
 * @ORM\Table(name="compost_collection_point")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CompostCollectionPointRepository")
 */
class CompostCollectionPoint
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
     * @Assert\NotBlank
     * @var string name
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;

    /**
     * @ORM\OrderBy({"date" = "desc"})
     * @ORM\OneToMany(targetEntity="CompostCollection", mappedBy="compost_collection_point")
     */
    protected $compost_collections;

    private $last_compost_collection;

    private $year_collections;//array con los totales por año;

    /**
     * @return mixed
     */
    public function getYearCollections()
    {
        return $this->year_collections;
    }

    /**
     * @param mixed $year_collections
     * @return CompostCollectionPoint
     */
    public function setYearCollections($year_collections)
    {
        $this->year_collections = $year_collections;
        return $this;
    }


    public function getName()
    {
        return $this->name;
    }

    public function __toString()
    {
        return $this->getName();
    }

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
     * Set name
     *
     * @param string $name
     * @return CompostCollectionPoint
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Add compost_collections
     *
     * @param \Gallinas\AppBundle\Entity\CompostCollection $compostCollections
     * @return CompostCollectionPoint
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

    public function getLastCompostCollection()
    {
        return $this->last_compost_collection;
    }

    public function setLastCompostCollection(CompostCollection $collection)
    {

        $this->last_compost_collection=$collection;

        return $this;

    }
}
