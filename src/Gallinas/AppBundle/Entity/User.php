<?php
/**
 *
 * User: paco
 * Date: 4/11/14
 * Time: 11:27
 */

namespace Gallinas\AppBundle\Entity;


use FOS\UserBundle\Model\User as BaseUser;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_user")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\UserRepository")
 */
class User extends BaseUser
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

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
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Lay", mappedBy="user")
     */
    protected $lays;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Collect", mappedBy="user")
     */
    protected $collects;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Gift", mappedBy="user")
     */
    protected $gifts;


    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Sale", mappedBy="user")
     */
    protected $sales;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Purchase", mappedBy="user")
     */
    protected $purchases;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\EggRegistryUser", mappedBy="user")
     */
    protected $egg_registry_users;

    /**
     * @ORM\OneToMany(targetEntity="Fowl", mappedBy="fowl_user")
     */
    protected $fowls;

    /**
     * lista de tareas asociadas
     * @ORM\ManyToMany(targetEntity="Task", mappedBy="users")
     * @ORM\JoinTable(name="users_task")
     */
    private $tasks;


    private $consumed_product;

    public function __construct()
    {
        parent::__construct();
        // your own logic
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
     * Set created
     *
     * @param \DateTime $created
     * @return User
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
     * @return User
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
     * Add lays
     *
     * @param \Gallinas\AppBundle\Entity\Lay $lays
     * @return User
     */
    public function addLay(\Gallinas\AppBundle\Entity\Lay $lays)
    {
        $this->lays[] = $lays;

        return $this;
    }

    /**
     * Remove lays
     *
     * @param \Gallinas\AppBundle\Entity\Lay $lays
     */
    public function removeLay(\Gallinas\AppBundle\Entity\Lay $lays)
    {
        $this->lays->removeElement($lays);
    }

    /**
     * Get lays
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getLays()
    {
        return $this->lays;
    }

    /**
     * Add collects
     *
     * @param \Gallinas\AppBundle\Entity\Collect $collects
     * @return User
     */
    public function addCollect(\Gallinas\AppBundle\Entity\Collect $collects)
    {
        $this->collects[] = $collects;

        return $this;
    }

    /**
     * Remove collects
     *
     * @param \Gallinas\AppBundle\Entity\Collect $collects
     */
    public function removeCollect(\Gallinas\AppBundle\Entity\Collect $collects)
    {
        $this->collects->removeElement($collects);
    }

    /**
     * Get collects
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getCollects()
    {
        return $this->collects;
    }

    /**
     * Add sales
     *
     * @param \Gallinas\AppBundle\Entity\Sale $sales
     * @return User
     */
    public function addSale(\Gallinas\AppBundle\Entity\Sale $sales)
    {
        $this->sales[] = $sales;

        return $this;
    }

    /**
     * Remove sales
     *
     * @param \Gallinas\AppBundle\Entity\Sale $sales
     */
    public function removeSale(\Gallinas\AppBundle\Entity\Sale $sales)
    {
        $this->sales->removeElement($sales);
    }

    /**
     * Get sales
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getSales()
    {
        return $this->sales;
    }

    /**
     * Add purchases
     *
     * @param \Gallinas\AppBundle\Entity\Purchase $purchases
     * @return User
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

    /**
     * Add gifts
     *
     * @param \Gallinas\AppBundle\Entity\Gift $gifts
     * @return User
     */
    public function addGift(\Gallinas\AppBundle\Entity\Gift $gifts)
    {
        $this->gifts[] = $gifts;

        return $this;
    }

    /**
     * Remove gifts
     *
     * @param \Gallinas\AppBundle\Entity\Gift $gifts
     */
    public function removeGift(\Gallinas\AppBundle\Entity\Gift $gifts)
    {
        $this->gifts->removeElement($gifts);
    }

    /**
     * Get gifts
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getGifts()
    {
        return $this->gifts;
    }

    public function  getConsumedProduct()
    {
        return $this->consumed_product;
    }

    public function setConsumedProduct($product)
    {
        $this->consumed_product=$product;
    }

    /**
     * Add egg_registry_users
     *
     * @param \Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers
     * @return User
     */
    public function addEggRegistryUser(\Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers)
    {
        $this->egg_registry_users[] = $eggRegistryUsers;

        return $this;
    }

    /**
     * Remove egg_registry_users
     *
     * @param \Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers
     */
    public function removeEggRegistryUser(\Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers)
    {
        $this->egg_registry_users->removeElement($eggRegistryUsers);
    }

    /**
     * Get egg_registry_users
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getEggRegistryUsers()
    {
        return $this->egg_registry_users;
    }

    /**
     * Add fowls
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowls
     * @return User
     */
    public function addFowl(\Gallinas\AppBundle\Entity\Fowl $fowls)
    {
        $this->fowls[] = $fowls;

        return $this;
    }

    /**
     * Remove fowls
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowls
     */
    public function removeFowl(\Gallinas\AppBundle\Entity\Fowl $fowls)
    {
        $this->fowls->removeElement($fowls);
    }

    /**
     * Get fowls
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getFowls()
    {
        return $this->fowls;
    }

    /**
     * Add task
     *
     * @param \Gallinas\AppBundle\Entity\Task $task
     *
     * @return User
     */
    public function addTask(\Gallinas\AppBundle\Entity\Task $task)
    {
        $this->tasks[] = $task;

        return $this;
    }

    /**
     * Remove task
     *
     * @param \Gallinas\AppBundle\Entity\Task $task
     */
    public function removeTask(\Gallinas\AppBundle\Entity\Task $task)
    {
        $this->tasks->removeElement($task);
    }

    /**
     * Get tasks
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getTasks()
    {
        return $this->tasks;
    }
}
