<?php
/**
 *
 * User: paco
 * Date: 4/11/14
 * Time: 11:27
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use FOS\UserBundle\Model\User as BaseUser;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_user")
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
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
     * @ORM\OneToMany(targetEntity="App\Entity\Lay", mappedBy="user")
     */
    protected $lays;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Collect", mappedBy="user")
     */
    protected $collects;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Gift", mappedBy="user")
     */
    protected $gifts;


    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Sale", mappedBy="user")
     */
    protected $sales;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Purchase", mappedBy="user")
     */
    protected $purchases;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\EggRegistryUser", mappedBy="user")
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
        $this->lays = new ArrayCollection();
        $this->collects = new ArrayCollection();
        $this->gifts = new ArrayCollection();
        $this->sales = new ArrayCollection();
        $this->purchases = new ArrayCollection();
        $this->egg_registry_users = new ArrayCollection();
        $this->fowls = new ArrayCollection();
        $this->tasks = new ArrayCollection();
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
     * @param \App\Entity\Lay $lays
     * @return User
     */
    public function addLay(\App\Entity\Lay $lays)
    {
        $this->lays[] = $lays;

        return $this;
    }

    /**
     * Remove lays
     *
     * @param \App\Entity\Lay $lays
     */
    public function removeLay(\App\Entity\Lay $lays)
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
     * @param \App\Entity\Collect $collects
     * @return User
     */
    public function addCollect(\App\Entity\Collect $collects)
    {
        $this->collects[] = $collects;

        return $this;
    }

    /**
     * Remove collects
     *
     * @param \App\Entity\Collect $collects
     */
    public function removeCollect(\App\Entity\Collect $collects)
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
     * @param \App\Entity\Sale $sales
     * @return User
     */
    public function addSale(\App\Entity\Sale $sales)
    {
        $this->sales[] = $sales;

        return $this;
    }

    /**
     * Remove sales
     *
     * @param \App\Entity\Sale $sales
     */
    public function removeSale(\App\Entity\Sale $sales)
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
     * @param \App\Entity\Purchase $purchases
     * @return User
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

    /**
     * Add gifts
     *
     * @param \App\Entity\Gift $gifts
     * @return User
     */
    public function addGift(\App\Entity\Gift $gifts)
    {
        $this->gifts[] = $gifts;

        return $this;
    }

    /**
     * Remove gifts
     *
     * @param \App\Entity\Gift $gifts
     */
    public function removeGift(\App\Entity\Gift $gifts)
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
     * @param \App\Entity\EggRegistryUser $eggRegistryUsers
     * @return User
     */
    public function addEggRegistryUser(\App\Entity\EggRegistryUser $eggRegistryUsers)
    {
        $this->egg_registry_users[] = $eggRegistryUsers;

        return $this;
    }

    /**
     * Remove egg_registry_users
     *
     * @param \App\Entity\EggRegistryUser $eggRegistryUsers
     */
    public function removeEggRegistryUser(\App\Entity\EggRegistryUser $eggRegistryUsers)
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
     * @param \App\Entity\Fowl $fowls
     * @return User
     */
    public function addFowl(\App\Entity\Fowl $fowls)
    {
        $this->fowls[] = $fowls;

        return $this;
    }

    /**
     * Remove fowls
     *
     * @param \App\Entity\Fowl $fowls
     */
    public function removeFowl(\App\Entity\Fowl $fowls)
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
     * @param \App\Entity\Task $task
     *
     * @return User
     */
    public function addTask(\App\Entity\Task $task)
    {
        $this->tasks[] = $task;

        return $this;
    }

    /**
     * Remove task
     *
     * @param \App\Entity\Task $task
     */
    public function removeTask(\App\Entity\Task $task)
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
