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
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Video
 *
 * @ORM\Table(name="video")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\VideoRepository")
 */
class Video
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
     * @ORM\Column(name="object_class", type="string", length=255)
     */
    protected $objectClass;

    /**
     * @var string $foreignKey
     *
     * @ORM\Column(name="foreign_key", type="string", length=64)
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
     * @var string $video_id
     * @Assert\NotBlank
     * @Assert\Length(max="255", min="5")
     * @ORM\Column(name="video_id", type="string", length=255)
     */
    private $video_id;

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
     * Constructor
     */
    public function __construct()
    {
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
     * @return Video
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
     * @return Video
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
     * @return Video
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
     * Set htmlContent
     *
     * @param string $videoId
     *
     * @return Video
     */
    public function setvideoId($videoId)
    {
        $this->video_id = $videoId;

        return $this;
    }

    /**
     * Get htmlContent
     *
     * @return string
     */
    public function getvideoId()
    {
        return $this->video_id;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Video
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
     * @return Video
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
     * @return Video
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


    public function __toString()
    {
        return $this->getTitle();
    }


    public function getClass()
    {
        return "Video";
    }
}
