<?php
/**
 * Created by diphda.net.
 * User: paco
 * Tipo de pago de cuota, mensual o anual
 * Date: 30/09/15
 * Time: 21:22
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\SharePayment
 *
 * @ORM\Table(name="share_payment")
 * @ORM\Entity(repositoryClass="App\Repository\SharePaymentRepository").
 */
class SharePayment
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
     * @Assert\Length(max="255", min="5")
     * @ORM\Column(name="title", type="string", length=255)
     */
    private $name;

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
     * @ORM\OneToMany(targetEntity="Partner", mappedBy="share_payment")
     */
    protected $partners;

    public function __construct()
    {
        $this->partners = new ArrayCollection();
    }









    public function __toString()
    {
        return $this->getName();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return dateTime
     */
    public function getCreated(): ? \DateTime
    {
        return $this->created;
    }

    /**
     * @param dateTime $created
     */
    public function setCreated(\DateTime $created): void
    {
        $this->created = $created;
    }

    /**
     * @return dateTime
     */
    public function getUpdated(): ? \DateTime
    {
        return $this->updated;
    }

    /**
     * @param dateTime $updated
     */
    public function setUpdated(\DateTime $updated): void
    {
        $this->updated = $updated;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @return Collection|Partner[]
     */
    public function getPartners(): Collection
    {
        return $this->partners;
    }

    public function addPartner(Partner $partner): self
    {
        if (!$this->partners->contains($partner)) {
            $this->partners[] = $partner;
            $partner->setSharePayment($this);
        }

        return $this;
    }

    public function removePartner(Partner $partner): self
    {
        if ($this->partners->contains($partner)) {
            $this->partners->removeElement($partner);
            // set the owning side to null (unless already changed)
            if ($partner->getSharePayment() === $this) {
                $partner->setSharePayment(null);
            }
        }

        return $this;
    }


}
