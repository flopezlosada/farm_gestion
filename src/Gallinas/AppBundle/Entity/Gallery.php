<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 30/09/15
 * Time: 21:22
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Translatable;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Gallery
 *
 * @ORM\Table(name="gallery")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\GalleryRepository").
 */
class Gallery
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
     * @var string $objectClass
     *
     * @ORM\Column(name="object_class", type="string", length=255, nullable=true)
     */
    protected $objectClass;

    /**
     * @var string $foreignKey
     *
     * @ORM\Column(name="foreign_key", type="string", length=64, nullable=true)
     */
    protected $foreignKey;


    /**
     * @var string $title
     * @Assert\NotBlank
     * @Assert\Length(max="255", min="5")

     * @ORM\Column(name="title", type="string", length=255)
     */
    private $title;


    /**
     * @var string $content
     * @Assert\NotBlank

     * @ORM\Column(name="content", type="text")
     */
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

    /**
     * @var string $slug
     * @Gedmo\Slug(fields={"title"})

     * @ORM\Column(name="slug", type="string", length=255)
     */
    private $slug;


    /**
     * @ORM\OneToMany(targetEntity="Image", mappedBy="gallery")
     */
    protected $images;



    /**
     * Constructor
     */
    public function __construct()
    {
        $this->images = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set objectClass
     *
     * @param string $objectClass
     *
     * @return Gallery
     */
    public function setObjectClass($objectClass)
    {
        $this->objectClass = $objectClass;

        return $this;
    }

    /**
     * Get objectClass
     *
     * @return string
     */
    public function getObjectClass()
    {
        return $this->objectClass;
    }

    /**
     * Set foreignKey
     *
     * @param string $foreignKey
     *
     * @return Gallery
     */
    public function setForeignKey($foreignKey)
    {
        $this->foreignKey = $foreignKey;

        return $this;
    }

    /**
     * Get foreignKey
     *
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignKey;
    }

    /**
     * Set title
     *
     * @param string $title
     *
     * @return Gallery
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set content
     *
     * @param string $content
     *
     * @return Gallery
     */
    public function setContent($content)
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Get content
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Gallery
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
     *
     * @return Gallery
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
     * Set slug
     *
     * @param string $slug
     *
     * @return Gallery
     */
    public function setSlug($slug)
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * Get slug
     *
     * @return string
     */
    public function getSlug()
    {
        return $this->slug;
    }

    /**
     * Add image
     *
     * @param \Gallinas\AppBundle\Entity\Image $image
     *
     * @return Gallery
     */
    public function addImage(Image $image)
    {
        $this->images[] = $image;

        return $this;
    }

    /**
     * Remove image
     *
     * @param \Gallinas\AppBundle\Entity\Image $image
     */
    public function removeImage(Image $image)
    {
        $this->images->removeElement($image);
    }

    /**
     * Get images
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getImages()
    {
        return $this->images;
    }

    public function __toString()
    {
        return $this->getTitle();
    }

    public function getClass()
    {
        return "Gallery";
    }
}
