<?php
/**
 * Proveedores de pienso, gallinas, pollos, pavos, etc
 * User: paco
 * Date: 4/11/14
 * Time: 12:31
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Provider
 *
 * @ORM\Table(name="provider")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\ProviderRepository")
 */
class Provider
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
     * @Assert\NotBlank
     * @var string $contact
     * @ORM\Column(name="contact", type="string", length=255, nullable=true)
     */
    private $contact;

    /**
     * @Assert\NotBlank
     * @var string address
     * @ORM\Column(name="address", type="string", length=255)
     */
    private $address;


    /**
     * @Assert\NotBlank
     * @var smallint $state
     * @ORM\ManyToOne(targetEntity="State", inversedBy="providers")
     */
    private $state;

    /**
     * @Assert\NotBlank
     * @var smallint $city
     * @ORM\ManyToOne(targetEntity="City", inversedBy="providers")
     */
    private $city;

    /**
     * @Assert\NotBlank
     * @var smallint $country
     * @ORM\ManyToOne(targetEntity="Country", inversedBy="providers")
     */
    private $country;

    /**
     *
     * @Assert\Length(min=5,minMessage="El código postal debe tener al menos {{limit}} caracteres",
     * max=5,maxMessage="El código postal debe tener como máximo {{limit}} caracteres")
     * @var integer postal_code
     * @ORM\Column(name="postal_code", type="integer", length=5,nullable=true)
     */
    private $postal_code;

    /**
     * @Assert\Length(min=9,minMessage="Un número de teléfono debe tener al menos {{limit}} caracteres",
     * max=20,maxMessage="Un número de teléfono debe tener como máximo {{limit}} caracteres")
     * @var string phone
     * @ORM\Column(name="phone", type="string", length=20,nullable=true)
     */
    private $phone;

    /**
     * @Assert\Length(min=9,minMessage="Un número de teléfono móvil debe tener al menos {{limit}} caracteres",
     * max=20,maxMessage="Un número de teléfono móvil debe tener como máximo {{limit}} caracteres")
     * @var string celular
     * @ORM\Column(name="celular", type="string", length=20,nullable=true)
     */
    private $celular;


    /**
     * @Assert\Url()
     * @var string web
     * @ORM\Column(name="web", type="string", length=255,nullable=true)
     */
    private $web;

    /**
     * @ORM\Column(type="string",nullable=true, length=255)
     * @Assert\Email
     * @var string
     */
    private $email;

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
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Purchase", mappedBy="provider")
     */
    protected $purchases;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->purchases = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return Provider
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
     * Set contact
     *
     * @param string $contact
     * @return Provider
     */
    public function setContact($contact)
    {
        $this->contact = $contact;

        return $this;
    }

    /**
     * Get contact
     *
     * @return string 
     */
    public function getContact()
    {
        return $this->contact;
    }

    /**
     * Set address
     *
     * @param string $address
     * @return Provider
     */
    public function setAddress($address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Get address
     *
     * @return string 
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Set postal_code
     *
     * @param integer $postalCode
     * @return Provider
     */
    public function setPostalCode($postalCode)
    {
        $this->postal_code = $postalCode;

        return $this;
    }

    /**
     * Get postal_code
     *
     * @return integer 
     */
    public function getPostalCode()
    {
        return $this->postal_code;
    }

    /**
     * Set phone
     *
     * @param string $phone
     * @return Provider
     */
    public function setPhone($phone)
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * Get phone
     *
     * @return string 
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Set celular
     *
     * @param string $celular
     * @return Provider
     */
    public function setCelular($celular)
    {
        $this->celular = $celular;

        return $this;
    }

    /**
     * Get celular
     *
     * @return string 
     */
    public function getCelular()
    {
        return $this->celular;
    }

    /**
     * Set web
     *
     * @param string $web
     * @return Provider
     */
    public function setWeb($web)
    {
        $this->web = $web;

        return $this;
    }

    /**
     * Get web
     *
     * @return string 
     */
    public function getWeb()
    {
        return $this->web;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Provider
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
     * @return Provider
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
     * Set state
     *
     * @param \Gallinas\AppBundle\Entity\State $state
     * @return Provider
     */
    public function setState(\Gallinas\AppBundle\Entity\State $state = null)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get state
     *
     * @return \Gallinas\AppBundle\Entity\State 
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Set city
     *
     * @param \Gallinas\AppBundle\Entity\City $city
     * @return Provider
     */
    public function setCity(\Gallinas\AppBundle\Entity\City $city = null)
    {
        $this->city = $city;

        return $this;
    }

    /**
     * Get city
     *
     * @return \Gallinas\AppBundle\Entity\City 
     */
    public function getCity()
    {
        return $this->city;
    }

    /**
     * Set country
     *
     * @param \Gallinas\AppBundle\Entity\Country $country
     * @return Provider
     */
    public function setCountry(\Gallinas\AppBundle\Entity\Country $country = null)
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Get country
     *
     * @return \Gallinas\AppBundle\Entity\Country 
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * Add purchases
     *
     * @param \Gallinas\AppBundle\Entity\Purchase $purchases
     * @return Provider
     */
    public function addPurchase(\Gallinas\AppBundle\Entity\Purchase $purchases)
    {
        $this->purchases[] = $purchases;

        return $this;
    }

    /**
     * Remove purchases
     *
     * @param \Gallinas\AppBundle\Entity\Purchase $purchases
     */
    public function removePurchase(\Gallinas\AppBundle\Entity\Purchase $purchases)
    {
        $this->purchases->removeElement($purchases);
    }

    /**
     * Get purchases
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getPurchases()
    {
        return $this->purchases;
    }

   public function __toString()
   {
       return $this->getName();
   }

    /**
     * Set email
     *
     * @param string $email
     * @return Provider
     */
    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Get email
     *
     * @return string 
     */
    public function getEmail()
    {
        return $this->email;
    }
}
