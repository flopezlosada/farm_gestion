<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="partner", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="UNIQ_partner_celular", columns={"celular"}),
 *     @ORM\UniqueConstraint(name="UNIQ_partner_email", columns={"email"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\PartnerRepository")
 * @ORM\HasLifecycleCallbacks
 */
#[UniqueEntity(fields: ['celular'], message: 'Ya existe un socio con ese teléfono.')]
#[UniqueEntity(fields: ['email'], message: 'Ya existe un socio con ese correo electrónico.')]
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
     * Nombre de reparto: cómo aparece la familia en el PDF de reparto (apodos,
     * "X y Z", etc.), proveniente de `nombre_pdf` de reparto_definitivo.csv.
     * Difiere del nombre legal (name + surname, usado para cobros). Nullable:
     * si está vacío, {@see getNameForDelivery} cae al nombre legal.
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $display_name = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $DNI;

    /**
     * @ORM\Column(type="string", length=20, nullable=true, unique=true)
     */
    #[Assert\Length(min: 9, minMessage: 'Un número de teléfono debe tener al menos {{ limit }} caracteres.', max: 20, maxMessage: 'Un número de teléfono debe tener como máximo {{ limit }} caracteres.')]
    private $celular;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $address;

    /**
     * IBAN para la remesa SEPA. Pertenece al titular legal del socio.
     * Nullable mientras la importación inicial no haya cargado todos los IBANs.
     * @ORM\Column(type="string", length=34, nullable=true)
     */
    #[Assert\Length(max: 34)]
    private ?string $iban = null;

    /**
     * Observaciones libres sobre el socio (ej. cuotas especiales, acuerdos,
     * notas de administración). Equivale al campo `observaciones` de COBROS.
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

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
     * Episodios de pertenencia a la asociación. Si un socio se da de alta,
     * baja, y vuelve a alta meses después, hay 2 PartnerMembershipPeriod.
     * Permite reconstruir gráficos históricos de socios activos por fecha.
     *
     * @ORM\OneToMany(targetEntity="PartnerMembershipPeriod", mappedBy="partner", cascade={"persist","remove"})
     * @ORM\OrderBy({"start_date"="ASC"})
     */
    private $membership_periods;


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
     * @ORM\Column(type="string", length=255, nullable=true, unique=true)
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

    public const STATUS_ACTIVO = 'ACTIVO';
    public const STATUS_PAUSADO = 'PAUSADO';
    public const STATUS_BAJA = 'BAJA';
    public const STATUSES = [self::STATUS_ACTIVO, self::STATUS_PAUSADO, self::STATUS_BAJA];

    /**
     * Estado actual del socio en el workflow de pertenencia. Sustituye
     * progresivamente a is_active (que se mantiene como mirror temporal
     * hasta migrar todas las consultas).
     *
     *   ACTIVO   - recibe cesta y aparece en listados de reparto
     *   PAUSADO  - vínculo activo pero no recibe cesta (vacaciones, baja médica)
     *   BAJA     - dejó la asociación; el histórico se conserva pero no genera reparto
     *
     * @ORM\Column(type="string", length=20, options={"default": "ACTIVO"})
     */
    private string $status = self::STATUS_ACTIVO;

    /**
     * Marca de reconciliación: socio traído del dump de producción que
     * administración aún no ha validado (¿alta real, baja histórica o
     * duplicado de una ficha ya existente?). Se lista en el panel "Socios por
     * confirmar"; al resolverlo se apaga. Los socios ya consolidados nacen en false.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $needs_review = false;

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
        $this->membership_periods = new ArrayCollection();
    }

    /**
     * @return Collection|PartnerMembershipPeriod[]
     */
    public function getMembershipPeriods(): Collection
    {
        return $this->membership_periods;
    }

    public function addMembershipPeriod(PartnerMembershipPeriod $period): self
    {
        if (!$this->membership_periods->contains($period)) {
            $this->membership_periods[] = $period;
            $period->setPartner($this);
        }
        return $this;
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

    public function setSurname(?string $surname): self
    {
        $this->surname = $surname;

        return $this;
    }

    /**
     * Valor crudo del nombre de reparto (puede ser null). Es el que edita el
     * formulario; la presentación con fallback va por {@see getNameForDelivery}.
     */
    public function getDisplayName(): ?string
    {
        return $this->display_name;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->display_name = $displayName !== null && trim($displayName) !== ''
            ? trim($displayName)
            : null;

        return $this;
    }

    /**
     * Nombre a mostrar en listados de reparto: el `display_name` (apodo de
     * reparto) si existe, o el nombre legal en title case como fallback.
     *
     * @return string Nunca vacío salvo que name y surname también lo estén.
     */
    public function getNameForDelivery(): string
    {
        if ($this->display_name !== null && trim($this->display_name) !== '') {
            return $this->display_name;
        }

        return $this->getLegalName();
    }

    /**
     * Nombre legal completo (name + surname) en title case. Los datos en
     * BBDD están en mayúsculas (vienen del import); aquí se normalizan para
     * presentación. No restaura acentos ausentes en origen.
     *
     * @return string Cadena vacía si no hay name ni surname.
     */
    public function getLegalName(): string
    {
        $legal = trim(($this->name ?? '') . ' ' . ($this->surname ?? ''));

        return $legal === '' ? '' : mb_convert_case($legal, MB_CASE_TITLE, 'UTF-8');
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

    public function getcelular(): ?string
    {
        return $this->celular;
    }

    public function setcelular(?string $celular): self
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

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): self
    {
        $this->iban = $iban !== null ? strtoupper(preg_replace('/\s+/', '', $iban)) : null;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status Uno de Partner::STATUS_*.
     * @throws \InvalidArgumentException Si el valor no está en STATUSES.
     */
    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Estado desconocido: %s', $status));
        }
        $this->status = $status;
        // Mirror temporal: is_active sigue alimentando las consultas legacy.
        $this->is_active = ($status === self::STATUS_ACTIVO);
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

    public function isNeedsReview(): bool
    {
        return $this->needs_review;
    }

    public function setNeedsReview(bool $needs_review): self
    {
        $this->needs_review = $needs_review;

        return $this;
    }

}
