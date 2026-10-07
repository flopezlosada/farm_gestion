<?php
/**
 * Proveedores de pienso, gallinas, pollos, pavos, etc
 * User: paco
 * Date: 4/11/14
 * Time: 12:31
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Provider
 *
 * @ORM\Table(name="provider")
 * @ORM\Entity(repositoryClass="App\Repository\ProviderRepository")
 */
#[UniqueEntity(fields: ['taxId'], message: 'Ya hay un proveedor con ese CIF.')]
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
     * @ORM\Column(name="name", type="string", length=255)
     */
    #[Assert\NotBlank]
    private $name;

    /**
     * CIF o NIF. Es lo que identifica a un proveedor: el nombre se escribe de mil
     * maneras, el CIF no. Opcional porque los proveedores antiguos del comercio de la
     * granja se dieron de alta sin él.
     *
     * @ORM\Column(name="tax_id", type="string", length=20, nullable=true, unique=true)
     */
    #[Assert\Length(max: 20)]
    private ?string $taxId = null;

    /**
     * Cuenta a la que se le paga, para las transferencias.
     *
     * @ORM\Column(type="string", length=34, nullable=true)
     */
    #[Assert\Iban]
    private ?string $iban = null;

    /**
     * @var string $contact
     * @ORM\Column(name="contact", type="string", length=255, nullable=true)
     */
    private $contact;

    /**
     * Opcional: un proveedor que nace de una factura trae la dirección que ésta
     * imprima, y un ticket no imprime ninguna.
     *
     * @var string|null address
     * @ORM\Column(name="address", type="string", length=255, nullable=true)
     */
    private $address;

    /**
     * Municipio como texto, tal como viene en la factura. Las relaciones con las
     * tablas geográficas de abajo son del comercio de la granja y casi todas
     * apuntan a un municipio de relleno.
     *
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    #[Assert\Length(max: 100)]
    private ?string $town = null;

    /**
     * Provincia como texto, por lo mismo que el municipio. La pide el modelo 347.
     *
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    #[Assert\Length(max: 100)]
    private ?string $province = null;

    /**
     * @var smallint $state
     * @ORM\ManyToOne(targetEntity="State", inversedBy="providers")
     */
    private $state;

    /**
     * @var smallint $city
     * @ORM\ManyToOne(targetEntity="City", inversedBy="providers")
     */
    private $city;

    /**
     * @var smallint $country
     * @ORM\ManyToOne(targetEntity="Country", inversedBy="providers")
     */
    private $country;

    /**
     * Texto y no número: un código postal de Barcelona o de Albacete empieza por
     * cero y como número lo perdería.
     *
     * @var string|null postal_code
     * @ORM\Column(name="postal_code", type="string", length=10, nullable=true)
     */
    #[Assert\Regex(pattern: '/^\d{5}$/', message: 'El código postal son 5 cifras.')]
    private $postal_code;

    /**
     * @var string phone
     * @ORM\Column(name="phone", type="string", length=20,nullable=true)
     */
    #[Assert\Length(min: 9, minMessage: 'Un número de teléfono debe tener al menos {{limit}} caracteres', max: 20, maxMessage: 'Un número de teléfono debe tener como máximo {{limit}} caracteres')]
    private $phone;

    /**
     * @var string celular
     * @ORM\Column(name="celular", type="string", length=20,nullable=true)
     */
    #[Assert\Length(min: 9, minMessage: 'Un número de teléfono móvil debe tener al menos {{limit}} caracteres', max: 20, maxMessage: 'Un número de teléfono móvil debe tener como máximo {{limit}} caracteres')]
    private $celular;


    /**
     * @var string web
     * @ORM\Column(name="web", type="string", length=255,nullable=true)
     */
    #[Assert\Url]
    private $web;

    /**
     * @ORM\Column(type="string",nullable=true, length=255)
     * @var string
     */
    #[Assert\Email]
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
     * @ORM\OneToMany(targetEntity="App\Entity\Purchase", mappedBy="provider")
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
     * @param \App\Entity\State $state
     * @return Provider
     */
    public function setState(\App\Entity\State $state = null)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get state
     *
     * @return \App\Entity\State
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Set city
     *
     * @param \App\Entity\City $city
     * @return Provider
     */
    public function setCity(\App\Entity\City $city = null)
    {
        $this->city = $city;

        return $this;
    }

    /**
     * Get city
     *
     * @return \App\Entity\City
     */
    public function getCity()
    {
        return $this->city;
    }

    /**
     * Set country
     *
     * @param \App\Entity\Country $country
     * @return Provider
     */
    public function setCountry(\App\Entity\Country $country = null)
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Get country
     *
     * @return \App\Entity\Country
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * Add purchases
     *
     * @param \App\Entity\Purchase $purchases
     * @return Provider
     */
    public function addPurchase(\App\Entity\Purchase $purchases)
    {
        $this->purchases[] = $purchases;

        return $this;
    }

    /**
     * Remove purchases
     *
     * @param \App\Entity\Purchase $purchases
     */
    public function removePurchase(\App\Entity\Purchase $purchases)
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
       return (string) $this->getName();
   }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    /**
     * Guarda el CIF normalizado (mayúsculas, sin espacios ni guiones), que es como
     * se compara al reconocer al proveedor de una factura.
     *
     * @param string|null $taxId CIF o NIF tal como se escriba.
     */
    public function setTaxId(?string $taxId): self
    {
        $this->taxId = self::normalizeTaxId($taxId);

        return $this;
    }

    /**
     * Un CIF en la forma en que se compara: mayúsculas, sólo letras y cifras, y sin el
     * prefijo «ES» del NIF-IVA intracomunitario, con el que muchas facturas escriben el
     * mismo CIF (ESB84283902 = B84283902). Si no se quitara, el mismo proveedor quedaría
     * dado de alta dos veces.
     *
     * @param string|null $taxId CIF o NIF tal como venga.
     */
    public static function normalizeTaxId(?string $taxId): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $taxId));
        $clean = (string) preg_replace('/^ES([0-9A-Z]\d{7}[0-9A-Z])$/', '$1', $clean);

        return $clean === '' ? null : mb_substr($clean, 0, 20);
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    /**
     * @param string|null $iban IBAN, se guarda sin espacios y en mayúsculas.
     */
    public function setIban(?string $iban): self
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));
        $this->iban = $clean === '' ? null : $clean;

        return $this;
    }

    public function getTown(): ?string
    {
        return $this->town;
    }

    public function setTown(?string $town): self
    {
        $this->town = $town;

        return $this;
    }

    public function getProvince(): ?string
    {
        return $this->province;
    }

    public function setProvince(?string $province): self
    {
        $this->province = $province;

        return $this;
    }

    /**
     * Completa los datos que falten con los de una factura, sin pisar nada de lo que
     * ya haya: lo que se escribió a mano en la ficha vale más que lo que imprima una
     * factura cualquiera (que puede traer la dirección de una sucursal).
     *
     * @param string|null $address    Dirección.
     * @param string|null $postalCode Código postal.
     * @param string|null $town       Municipio.
     * @param string|null $province   Provincia.
     */
    public function fillBlanks(?string $address, ?string $postalCode, ?string $town, ?string $province): void
    {
        $this->address = self::blank($this->address) ? $address : $this->address;
        $this->postal_code = self::blank($this->postal_code) ? $postalCode : $this->postal_code;
        $this->town = self::blank($this->town) ? $town : $this->town;
        $this->province = self::blank($this->province) ? $province : $this->province;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
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
