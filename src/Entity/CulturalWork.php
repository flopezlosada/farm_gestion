<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 8/11/17
 * Time: 18:51
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\CulturalWork
 *
 * @ORM\Table(name="cultural_work")
 * @ORM\Entity(repositoryClass="App\Repository\CulturalWorkRepository")
 */

class CulturalWork
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
     * @var smallint $cultural_work_type
     * @ORM\ManyToOne(targetEntity="CulturalWorkType", inversedBy="cultural_works")
     */
    #[Assert\NotBlank]
    private $cultural_work_type;

    /**
     * Devuelve una lista de cultivos a las que se asocia el trabajo
     * @ORM\ManyToMany(targetEntity="CropWorking", inversedBy="cultural_works")
     * @ORM\JoinTable(name="crop_working_cultural_work")
     */
    private $crop_workings;


    /**
     * Devuelve una lista de cultivos a las que se asocia el trabajo
     * @ORM\ManyToMany(targetEntity="Sector", inversedBy="cultural_works")
     * @ORM\JoinTable(name="sector_cultural_work")
     */
    private $sectors;

    /**
     * Fecha realización,
     * @var string $date
     * @ORM\Column(name="date", type="date", nullable=true)
     */
    #[Assert\Date]
    private $date;

    /**
     * @var string $content
     * @ORM\Column(name="content", type="text")
     */
    #[Assert\NotBlank]
    private $content;



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



    public function __construct()
    {
        $this->crop_workings = new ArrayCollection();
        $this->sectors = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate( $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function getUpdated(): ?\DateTimeInterface
    {
        return $this->updated;
    }

    public function setUpdated(\DateTimeInterface $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    public function getCulturalWorkType(): ?CulturalWorkType
    {
        return $this->cultural_work_type;
    }

    public function setCulturalWorkType(?CulturalWorkType $cultural_work_type): self
    {
        $this->cultural_work_type = $cultural_work_type;

        return $this;
    }

    /**
     * @return Collection|CropWorking[]
     */
    public function getCropWorkings(): Collection
    {
        return $this->crop_workings;
    }

    public function addCropWorking(CropWorking $cropWorking): self
    {
        if (!$this->crop_workings->contains($cropWorking)) {
            $this->crop_workings[] = $cropWorking;
        }

        return $this;
    }

    public function removeCropWorking(CropWorking $cropWorking): self
    {
        if ($this->crop_workings->contains($cropWorking)) {
            $this->crop_workings->removeElement($cropWorking);
        }

        return $this;
    }

    /**
     * @return Collection|Sector[]
     */
    public function getSectors(): Collection
    {
        return $this->sectors;
    }

    public function addSector(Sector $sector): self
    {
        if (!$this->sectors->contains($sector)) {
            $this->sectors[] = $sector;
        }

        return $this;
    }

    public function removeSector(Sector $sector): self
    {
        if ($this->sectors->contains($sector)) {
            $this->sectors->removeElement($sector);
        }

        return $this;
    }

    public function __toString()
    {
        return $this->getCulturalWorkType()->getTitle();
    }
}


