<?php
/**
 * Estado de los animales de un lote. Puede ser vivo, muerte accidental, muerte enfermedad, sacrificado
 * User: paco
 * Date: 11/03/15
 * Time: 11:47
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\BatchStatus
 *
 * @ORM\Table(name="batch_status")
 * @ORM\Entity(repositoryClass="App\Repository\BatchStatusRepository")
 */
class BatchStatus
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
     * @ORM\OneToMany(targetEntity="Batch", mappedBy="batch_status")
     */
    protected $batchs;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->batchs = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return BatchStatus
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
     * Add batchs
     *
     * @param \App\Entity\Batch $batchs
     * @return BatchStatus
     */
    public function addBatch(\App\Entity\Batch $batchs)
    {
        $this->batchs[] = $batchs;

        return $this;
    }

    /**
     * Remove batchs
     *
     * @param \App\Entity\Batch $batchs
     */
    public function removeBatch(\App\Entity\Batch $batchs)
    {
        $this->batchs->removeElement($batchs);
    }

    /**
     * Get batchs
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getBatchs()
    {
        return $this->batchs;
    }

    public function __toString()
    {
        return $this->getName();
    }
}
