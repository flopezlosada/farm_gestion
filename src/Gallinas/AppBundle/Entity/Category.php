<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 30/09/15
 * Time: 21:22
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Category
 *
 * @ORM\Table(name="category")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CategoryRepository").
 */
class Category
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
     * @var string $slug
     * @Gedmo\Slug(fields={"name"})
     * @Gedmo\Translatable
     * @ORM\Column(name="slug", type="string", length=255)
     */
    private $slug;


    /**
     * @ORM\OneToMany(targetEntity="Blog", mappedBy="category")
     */
    protected $blogs;

    public function __toString()
    {
        return $this->getName();
    }

    public function getClass()
    {
        return "Category";
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->blogs = new \Doctrine\Common\Collections\ArrayCollection();
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
     *
     * @return Category
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
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Category
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
     * @return Category
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
     * @return Category
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
     * Add blog
     *
     * @param \Gallinas\AppBundle\Entity\Blog $blog
     *
     * @return Category
     */
    public function addBlog(Blog $blog)
    {
        $this->blogs[] = $blog;

        return $this;
    }

    /**
     * Remove blog
     *
     * @param \Gallinas\AppBundle\Entity\Blog $blog
     */
    public function removeBlog(Blog $blog)
    {
        $this->blogs->removeElement($blog);
    }

    /**
     * Get blogs
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getBlogs()
    {
        return $this->blogs;
    }
}
