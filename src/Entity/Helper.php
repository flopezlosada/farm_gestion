<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Voluntario de acogida del albergue: persona que viene a trabajar a cambio de
 * cama y comida (wwoofer, estudiante en prácticas, Workaway...). Se llama
 * "Helper" (término de WWOOF/Workaway/HelpX) y NO "Volunteer" a propósito: en
 * la asociación "voluntario" ya significa socix que colabora todo el año, y ese
 * será otro módulo. Mantener los dos mundos separados evita confundirlos.
 *
 * La persona vive una vez; sus visitas se modelan como {@see Stay} (un repetidor
 * no se vuelve a teclear). Los campos {@see Helper::$studies} y
 * {@see Helper::$university} sólo los rellenan los de prácticas; un wwoofer los
 * deja vacíos — son datos opcionales según la procedencia, no subclases.
 *
 * @ORM\Table(name="helper")
 * @ORM\Entity(repositoryClass="App\Repository\HelperRepository")
 */
class Helper
{
    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Nombre completo tal cual lo da la persona. Un solo campo a propósito:
     * separar nombre/apellidos parte mal los nombres extranjeros.
     *
     * @var string
     * @ORM\Column(name="name", type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private $name;

    /**
     * De dónde nos llega esta persona (WWOOF, prácticas...). Obligatoria: es la
     * dimensión por la que clasificamos a los voluntarios de acogida.
     *
     * @var HelperSource|null
     * @ORM\ManyToOne(targetEntity="HelperSource", inversedBy="helpers")
     * @ORM\JoinColumn(name="source_id", referencedColumnName="id", nullable=false)
     */
    #[Assert\NotNull]
    private $source;

    /**
     * @var string|null
     * @ORM\Column(name="country", type="string", length=120, nullable=true)
     */
    private $country;

    /**
     * @var string|null
     * @ORM\Column(name="email", type="string", length=180, nullable=true)
     */
    #[Assert\Email]
    private $email;

    /**
     * @var string|null
     * @ORM\Column(name="phone", type="string", length=40, nullable=true)
     */
    private $phone;

    /**
     * Idiomas que habla, texto libre (útil para asignar tareas y convivencia).
     *
     * @var string|null
     * @ORM\Column(name="languages", type="string", length=255, nullable=true)
     */
    private $languages;

    /**
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="birth_date", type="date_immutable", nullable=true)
     */
    private $birthDate;

    /**
     * Contacto de emergencia (texto libre: nombre + teléfono). No es dato legal
     * —eso se gestiona fuera de aquí— sino operativo de seguridad.
     *
     * @var string|null
     * @ORM\Column(name="emergency_contact", type="string", length=255, nullable=true)
     */
    private $emergencyContact;

    /**
     * Área de estudios. Sólo aplica a estudiantes en prácticas; null para el resto.
     *
     * @var string|null
     * @ORM\Column(name="studies", type="string", length=255, nullable=true)
     */
    private $studies;

    /**
     * Universidad, texto libre (en el Excel actual: CLM, UPM, UPV...). Se
     * ascenderá a catálogo sólo si hace falta estadística por universidad.
     *
     * @var string|null
     * @ORM\Column(name="university", type="string", length=255, nullable=true)
     */
    private $university;

    /**
     * @var string|null
     * @ORM\Column(name="notes", type="text", nullable=true)
     */
    private $notes;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;

    /**
     * @ORM\OneToMany(targetEntity="Stay", mappedBy="helper")
     */
    private $stays;

    /**
     * Inicializa la colección de estancias.
     */
    public function __construct()
    {
        $this->stays = new ArrayCollection();
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return HelperSource|null
     */
    public function getSource(): ?HelperSource
    {
        return $this->source;
    }

    /**
     * @param HelperSource|null $source
     * @return self
     */
    public function setSource(?HelperSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * @param string|null $country
     * @return self
     */
    public function setCountry(?string $country): self
    {
        $this->country = $country;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * @param string|null $email
     * @return self
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /**
     * @param string|null $phone
     * @return self
     */
    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getLanguages(): ?string
    {
        return $this->languages;
    }

    /**
     * @param string|null $languages
     * @return self
     */
    public function setLanguages(?string $languages): self
    {
        $this->languages = $languages;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    /**
     * @param \DateTimeImmutable|null $birthDate
     * @return self
     */
    public function setBirthDate(?\DateTimeImmutable $birthDate): self
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getEmergencyContact(): ?string
    {
        return $this->emergencyContact;
    }

    /**
     * @param string|null $emergencyContact
     * @return self
     */
    public function setEmergencyContact(?string $emergencyContact): self
    {
        $this->emergencyContact = $emergencyContact;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getStudies(): ?string
    {
        return $this->studies;
    }

    /**
     * @param string|null $studies
     * @return self
     */
    public function setStudies(?string $studies): self
    {
        $this->studies = $studies;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getUniversity(): ?string
    {
        return $this->university;
    }

    /**
     * @param string|null $university
     * @return self
     */
    public function setUniversity(?string $university): self
    {
        $this->university = $university;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @param string|null $notes
     * @return self
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    /**
     * @return \DateTime|null
     */
    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    /**
     * @return Collection<int, Stay>
     */
    public function getStays(): Collection
    {
        return $this->stays;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->name;
    }
}
