<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="partner")
 * @ORM\Entity(repositoryClass="App\Repository\PartnerRepository")
 * @ORM\HasLifecycleCallbacks
 */
class Partner
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    private $name;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\NotBlank]
    private $surname;

  
  

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $DNI;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    #[Assert\Type(type: 'integer', message: 'El valor {{value}} no es un {{type}} válido.')]
    #[Assert\GreaterThan(value: 0)]
    private $celular;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $address;

    /**
     * @var smallint $state
     * @ORM\ManyToOne(targetEntity="State", inversedBy="partners")
     */
    #[Assert\NotBlank]
    private $state;

    /**
     * @var smallint $city
     * @ORM\ManyToOne(targetEntity="City", inversedBy="partners")
     */
    #[Assert\NotBlank]
    private $city;


    /**
     * @var string $inscription_date
     * @ORM\Column(type="date", nullable=true)
     */
    private $inscription_date;

    /**
     * @var string $demote_date
     * @ORM\Column(type="date", nullable=true)
     */
    private $demote_date;


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
     * anual o mensual
     * @var smallint $share_payment
     * @ORM\ManyToOne(targetEntity="SharePayment", inversedBy="partners")
     */
    #[Assert\NotBlank]
    private $share_payment;


    /**
     * @var string $image
     *
     * @ORM\Column(name="image", type="string", length=255, nullable=true)
     * es la foto de la ficha de inscripción
     */
    private $registration_form_image;

    #[Assert\File(maxSize: 6000000)]
    protected $registration_form_file;

    /**
     * @var int modified
     * @ORM\Column(name="modified", type="boolean", length=1, nullable=true)
     * Lo uso para, cuando se edita la entidad y sólo se modifica el archivo de imagen, indicar que se ha modificado
     * y persistir la entidad, porque el atributo file no se controla mediante doctrine porque no está en la bbdd
     */
    private $modified;

    /**
     * Controla el histórico de tipos de cesta que ha ido teniendo. Puede que siempre haya tenido la misma y sólo habrá
     * un registro
     * @ORM\OneToMany(targetEntity="PartnerBasketShare", mappedBy="partner")
     * @ORM\OrderBy({"id"="ASC"})
     */
    private $partner_basket_shares;


    /**
     *
     * @ORM\ManyToOne(targetEntity="WeeklyBasketGroup", inversedBy="partners")
     *
     */
    protected $weekly_basket_group;


    /**
     * Controla el listado semanal de cestas que ha ido teniendo. Cada semana aparece 1 registro o cada 2 semanas
     * @ORM\OneToMany(targetEntity="WeeklyBasket", mappedBy="partner")
     *
     */
    private $weekly_baskets;


    /**
     * @var int $eat_meat
     * @ORM\Column(name="eat_meat", type="boolean", length=1, nullable=true)
     * si come carne recibirá correos al respecto
     */
    private $eat_meat;

    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\Email]
    private $email;


    /**
     * Tiene la ficha rellena?
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $has_file;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $is_active;

    /**
     * parientes
     * One Category has Many Categories.
     * @Orm\OneToMany(targetEntity="Partner", mappedBy="parent")
     */
    private $relatives;

    /**
     * Pariente principal de la familia. Si es nulo, es porque esta socia es la principal
     * Many Categories have One Category.
     * @Orm\ManyToOne(targetEntity="Partner", inversedBy="relatives")
     * @Orm\JoinColumn(name="parent_id", referencedColumnName="id")
     */
    private $parent;


    /**
     * para controlar la cesta actual de la semana, no pasa a la bbdd
     * para los listados de cestas semanales
     */
    private $current_basket;


    /**
     * Para los semanales de media cesta, hay que indicar con quién la comparten.
     * Este valor se rellenará en ambos socios, cada uno con el id del otro.
     * @Orm\OneToOne(targetEntity="Partner")
     * @Orm\JoinColumn(name="share_partner_id", referencedColumnName="id", nullable=true)
     */
    private $share_partner;



    /**
     * @var esto es un control para ver si los de media cesta han salido en el listado
     * No pasa a la bbdd
     */
    private $isListed=0;

    /**
     * Partner constructor.
     * @param $partner_basket_shares
     *
     */
    public function __construct()
    {
        $this->partner_basket_shares = new ArrayCollection();

        $this->weekly_baskets = new ArrayCollection();
        $this->relatives = new ArrayCollection();
    }


    public function getName2(): ?string
    {
        return $this->name2;
    }

    public function setName2($name2): self
    {
        $this->name2 = $name2;

        return $this;
    }





    public function getId(): ?int
    {
        return $this->id;
    }

    public function getname(): ?string
    {
        return $this->name;
    }

    public function setname(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(string $surname): self
    {
        $this->surname = $surname;

        return $this;
    }

    public function getDNI(): ?string
    {
        return $this->DNI;
    }

    public function setDNI(?string $DNI): self
    {
        $this->DNI = $DNI;

        return $this;
    }

    public function getcelular(): ?int
    {
        return $this->celular;
    }

    public function setcelular(?int $celular): self
    {
        $this->celular = $celular;

        return $this;
    }



    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }


    /**
     * Get getInscriptionDate
     *
     * @return \DateTime
     */
    public function getInscriptionDate()
    {
        return $this->inscription_date;
    }

    /**
     * Set inscription_date
     *
     * @param \DateTime $inscription_date
     * @return Partner
     */
    public function setInscriptionDate($inscription_date)
    {
        $this->inscription_date = $inscription_date;

        return $this;
    }


    public function getAbsolutePath()
    {
        return null === $this->registration_form_image ? null : $this->getUploadRootDir() . '/' . $this->registration_form_image;
    }

    public function getWebPath()
    {
        return null === $this->registration_form_image ? null : $this->getUploadDir() . '/' . $this->registration_form_image;
    }

    protected function getUploadRootDir()
    {
        // la ruta absoluta del directorio donde se deben guardar los archivos cargados
        return __DIR__ . '/../../public/' . $this->getUploadDir();
    }

    protected function getUploadDir()
    {
        // se libra del __DIR__ para no desviarse al mostrar `doc/image` en la vista.
        return 'uploads/partner/images';
    }

    /**
     * @ORM\PrePersist()
     * @ORM\PreUpdate()
     */
    public function preUpload()
    {
        if (null !== $this->registration_form_file)
        {
            // haz cualquier cosa para generar un nombre único
            if ($this->registration_form_image !== null)
            {
                $this->removeUpload();
            }
            $this->registration_form_image = uniqid("", true) . '.' . $this->registration_form_file->guessExtension();
        }
    }

    /**
     * @ORM\PostPersist()
     * @ORM\PostUpdate()
     */
    public function upload()
    {
        // la propiedad file puede estar vacía si el campo no es obligatorio
        if (null === $this->registration_form_file)
        {
            return;
        }

        // si hay un error al mover el archivo, move() automáticamente
        // envía una excepción. Esta impedirá que la entidad se persista
        // en la base de datos en caso de error
        $this->registration_form_file->move($this->getUploadRootDir(), $this->registration_form_image);

        unset($this->registration_form_file);
    }



    /**
     * @ORM\PostRemove()
     */
    public function removeUpload()
    {
        if ($file = $this->getAbsolutePath())
        {
            unlink($file);
        }
    }

    /**
     * @return mixed
     */
    public function getSurname2()
    {
        return $this->surname2;
    }

    /**
     * @param mixed $surname2
     */
    public function setSurname2($surname2): void
    {
        $this->surname2 = $surname2;
    }

    /**
     * @return \DateTime
     */
    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    /**
     * @param \DateTime $created
     */
    public function setCreated(\DateTime $created): void
    {
        $this->created = $created;
    }

    /**
     * @return \DateTime
     */
    public function getUpdated(): ? \DateTime
    {
        return $this->updated;
    }

    /**
     * @param \DateTime $updated
     */
    public function setUpdated(\DateTime $updated): void
    {
        $this->updated = $updated;
    }

    /**
     * @return smallint
     */
    public function getSharePayment(): ?SharePayment
    {
        return $this->share_payment;
    }

    /**
     * @param smallint $share_payment
     */
    public function setSharePayment(SharePayment $share_payment): self
    {
        $this->share_payment = $share_payment;

        return $this;
    }

    /**
     * @return string
     */
    public function getRegistrationFormImage(): ?string
    {
        return $this->registration_form_image;
    }

    /**
     * @param string $registration_form_image
     */
    public function setRegistrationFormImage(string $registration_form_image): void
    {
        $this->registration_form_image = $registration_form_image;
    }

    /**
     * @return mixed
     */
    public function getRegistrationFormFile()
    {
        return $this->registration_form_file;
    }

    /**
     * @param mixed $registration_form_file
     */
    public function setRegistrationFormFile($registration_form_file): void
    {
        $this->registration_form_file = $registration_form_file;
    }

    /**
     * @return int
     */
    public function getModified(): ?int
    {
        return $this->modified;
    }

    /**
     * @param int $modified
     */
    public function setModified(int $modified): void
    {
        $this->modified = $modified;
    }

    public function getemail(): ?string
    {
        return $this->email;
    }

    public function setemail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getEmail2(): ?string
    {
        return $this->email2;
    }

    public function setEmail2(?string $email2): self
    {
        $this->email2 = $email2;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPartnerBasketShares()
    {
        return $this->partner_basket_shares;
    }

    /**
     * @param mixed $basket_shares
     */
    public function setPartnerBasketShares($partner_basket_shares): void
    {
        $this->partner_basket_shares = $partner_basket_shares;
    }


    /**
     * Set state
     *
     * @param \App\Entity\State $state
     * @return Partner
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
     * @return Partner
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
     * @return int
     */
    public function getEatMeat()
    {
        return $this->eat_meat;
    }

    /**
     * @param int $eat_meat
     */
    public function setEatMeat(int $eat_meat): void
    {
        $this->eat_meat = $eat_meat;
    }



    public function __toString()
    {
        // TODO: Implement __toString() method.
        return $this->getname(). " ".$this->getSurname();
    }

    public function getHasFile(): ?bool
    {
        return $this->has_file;
    }

    public function setHasFile(?bool $has_file): self
    {
        $this->has_file = $has_file;

        return $this;
    }

    public function getIsActive(): ?bool
    {
        return $this->is_active;
    }

    public function setIsActive(?bool $is_active): self
    {
        $this->is_active = $is_active;

        return $this;
    }

    public function addPartnerBasketShare(PartnerBasketShare $partnerBasketShare): self
    {
        if (!$this->partner_basket_shares->contains($partnerBasketShare)) {
            $this->partner_basket_shares[] = $partnerBasketShare;
            $partnerBasketShare->setPartner($this);
        }

        return $this;
    }

    public function removePartnerBasketShare(PartnerBasketShare $partnerBasketShare): self
    {
        if ($this->partner_basket_shares->contains($partnerBasketShare)) {
            $this->partner_basket_shares->removeElement($partnerBasketShare);
            // set the owning side to null (unless already changed)
            if ($partnerBasketShare->getPartner() === $this) {
                $partnerBasketShare->setPartner(null);
            }
        }

        return $this;
    }


    /**
     * @return Collection|WeeklyBasket[]
     */
    public function getWeeklyBaskets(): Collection
    {
        return $this->weekly_baskets;
    }

    public function addWeeklyBasket(WeeklyBasket $weeklyBasket): self
    {
        if (!$this->weekly_baskets->contains($weeklyBasket)) {
            $this->weekly_baskets[] = $weeklyBasket;
            $weeklyBasket->setPartner($this);
        }

        return $this;
    }

    public function removeWeeklyBasket(WeeklyBasket $weeklyBasket): self
    {
        if ($this->weekly_baskets->contains($weeklyBasket)) {
            $this->weekly_baskets->removeElement($weeklyBasket);
            // set the owning side to null (unless already changed)
            if ($weeklyBasket->getPartner() === $this) {
                $weeklyBasket->setPartner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Partner[]
     */
    public function getRelatives(): Collection
    {
        return $this->relatives;
    }

    public function addRelative(Partner $relative): self
    {
        if (!$this->relatives->contains($relative)) {
            $this->relatives[] = $relative;
            $relative->setParent($this);
        }

        return $this;
    }

    public function removeRelative(Partner $relative): self
    {
        if ($this->relatives->contains($relative)) {
            $this->relatives->removeElement($relative);
            // set the owning side to null (unless already changed)
            if ($relative->getParent() === $this) {
                $relative->setParent(null);
            }
        }

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }


    public function getActiveBasket()
    {
        foreach ($this->getPartnerBasketShares() as $basket)
        {
            if ($basket->getIsActive()==1)
            {
                return $basket;
            }
        }

        return null;
    }

    public function hasActiveBasket()
    {
        if ($this->getActiveBasket())
        {
            return true;
        }

        return false;
    }

    public function getDate()
    {
        return $this->getInscriptionDate();
    }

    /**
     * @return WeeklyBasket
     */
    public function getCurrentBasket()
    {
        return $this->current_basket;
    }

    /**
     * @param mixed $current_basket
     */
    public function setCurrentBasket(WeeklyBasket $current_basket): void
    {
        $this->current_basket = $current_basket;
    }

    public function getWeeklyBasketGroup(): ?WeeklyBasketGroup
    {
        return $this->weekly_basket_group;
    }

    public function setWeeklyBasketGroup(?WeeklyBasketGroup $weekly_basket_group): self
    {
        $this->weekly_basket_group = $weekly_basket_group;

        return $this;
    }

    public function getSharePartner(): ?self
    {
        return $this->share_partner;
    }

    public function setSharePartner(?self $share_partner): self
    {
        $this->share_partner = $share_partner;

        return $this;
    }



    /**
     * @return mixed
     */
    public function getIsListed()
    {
        return $this->isListed;
    }

    /**
     * @param mixed $isListed
     */
    public function setIsListed($isListed): void
    {
        $this->isListed = $isListed;
    }

    public function getDemoteDate(): ?\DateTimeInterface
    {
        return $this->demote_date;
    }

    public function setDemoteDate(?\DateTimeInterface $demote_date): self
    {
        $this->demote_date = $demote_date;

        return $this;
    }



}
