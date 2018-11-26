<?php
/**
 * Created by PhpStorm.
 * User: paco
 * Date: 26/11/18
 * Time: 10:36
 * Representa la cesta de verduras semanal que se entrega a lxs socixs
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Basket
 *
 * @ORM\Table(name="basket")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\BasketRepository")
 */
class Basket
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
     * @var string $date
     * @ORM\Column(name="date", type="date")
     */
    private $date;

    /**
     * Kg de verdura total. Se calcula
     *
     *
     * @var smallint $amount
     * @ORM\Column(name="amount",type="integer",nullable=true)
     */
    private $amount;

    /**
     * Notas
     * @var string $note
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;

    /**
     * @ORM\OneToMany(targetEntity="Production", mappedBy="basket")
     */
    protected $productions;

    /**
     * Semana (dentro de cada año) a la que se refiere esta cesta. Se calcula automáticamente, Es para las estadísticas semanales
     * @var smallint $week
     * @ORM\Column(name="week", type="integer")
     */
    private $week;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->productions = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set date
     *
     * @param \DateTime $date
     * @return Basket
     */
    public function setDate($date)
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Get date
     *
     * @return \DateTime 
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return Basket
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
     * Set note
     *
     * @param string $note
     * @return Basket
     */
    public function setNote($note)
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Get note
     *
     * @return string 
     */
    public function getNote()
    {
        return $this->note;
    }

    /**
     * Set week
     *
     * @param integer $week
     * @return Basket
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
     * Add productions
     *
     * @param \Gallinas\AppBundle\Entity\Production $productions
     * @return Basket
     */
    public function addProduction(\Gallinas\AppBundle\Entity\Production $productions)
    {
        $this->productions[] = $productions;

        return $this;
    }

    /**
     * Remove productions
     *
     * @param \Gallinas\AppBundle\Entity\Production $productions
     */
    public function removeProduction(\Gallinas\AppBundle\Entity\Production $productions)
    {
        $this->productions->removeElement($productions);
    }

    /**
     * Get productions
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getProductions()
    {
        return $this->productions;
    }
}
