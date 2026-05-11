<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 * @ORM\Table(name="fos_user")
 */
class User implements UserInterface, PasswordAuthenticatedUserInterface, LegacyPasswordAuthenticatedUserInterface
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=180)
     */
    private $username;

    /**
     * @ORM\Column(name="username_canonical", type="string", length=180, unique=true)
     */
    private $usernameCanonical;

    /**
     * @ORM\Column(type="string", length=180)
     */
    private $email;

    /**
     * @ORM\Column(name="email_canonical", type="string", length=180, unique=true)
     */
    private $emailCanonical;

    /**
     * @ORM\Column(type="boolean")
     */
    private $enabled = true;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $salt;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $password;

    /**
     * @ORM\Column(name="last_login", type="datetime", nullable=true)
     */
    private $lastLogin;

    /**
     * @ORM\Column(name="confirmation_token", type="string", length=180, unique=true, nullable=true)
     */
    private $confirmationToken;

    /**
     * @ORM\Column(name="password_requested_at", type="datetime", nullable=true)
     */
    private $passwordRequestedAt;

    /**
     * @ORM\Column(type="array")
     */
    private $roles = [];

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
        $this->lays = new ArrayCollection();
        $this->collects = new ArrayCollection();
        $this->gifts = new ArrayCollection();
        $this->sales = new ArrayCollection();
        $this->purchases = new ArrayCollection();
        $this->egg_registry_users = new ArrayCollection();
        $this->fowls = new ArrayCollection();
        $this->tasks = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return (string) $this->username;
    }

    public function __toString(): string
    {
        return $this->username ?? 'Usuario #' . $this->id;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        $this->usernameCanonical = mb_strtolower($username);
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getUsernameCanonical(): ?string
    {
        return $this->usernameCanonical;
    }

    public function setUsernameCanonical(string $usernameCanonical): self
    {
        $this->usernameCanonical = $usernameCanonical;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        $this->emailCanonical = mb_strtolower($email);
        return $this;
    }

    public function getEmailCanonical(): ?string
    {
        return $this->emailCanonical;
    }

    public function setEmailCanonical(string $emailCanonical): self
    {
        $this->emailCanonical = $emailCanonical;
        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getSalt(): ?string
    {
        return $this->salt;
    }

    public function setSalt(?string $salt): self
    {
        $this->salt = $salt;
        return $this;
    }

    public function getLastLogin(): ?\DateTimeInterface
    {
        return $this->lastLogin;
    }

    public function setLastLogin(?\DateTimeInterface $lastLogin): self
    {
        $this->lastLogin = $lastLogin;
        return $this;
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function setConfirmationToken(?string $confirmationToken): self
    {
        $this->confirmationToken = $confirmationToken;
        return $this;
    }

    public function getPasswordRequestedAt(): ?\DateTimeInterface
    {
        return $this->passwordRequestedAt;
    }

    public function setPasswordRequestedAt(?\DateTimeInterface $passwordRequestedAt): self
    {
        $this->passwordRequestedAt = $passwordRequestedAt;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function setCreated($created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function setUpdated($updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function getUpdated(): ?\DateTimeInterface
    {
        return $this->updated;
    }

    public function addLay(\App\Entity\Lay $lay): self
    {
        $this->lays[] = $lay;
        return $this;
    }

    public function removeLay(\App\Entity\Lay $lay): void
    {
        $this->lays->removeElement($lay);
    }

    public function getLays(): Collection
    {
        return $this->lays;
    }

    public function addCollect(\App\Entity\Collect $collect): self
    {
        $this->collects[] = $collect;
        return $this;
    }

    public function removeCollect(\App\Entity\Collect $collect): void
    {
        $this->collects->removeElement($collect);
    }

    public function getCollects(): Collection
    {
        return $this->collects;
    }

    public function addSale(\App\Entity\Sale $sale): self
    {
        $this->sales[] = $sale;
        return $this;
    }

    public function removeSale(\App\Entity\Sale $sale): void
    {
        $this->sales->removeElement($sale);
    }

    public function getSales(): Collection
    {
        return $this->sales;
    }

    public function addPurchase(\App\Entity\Purchase $purchase): self
    {
        $this->purchases[] = $purchase;
        return $this;
    }

    public function removePurchase(\App\Entity\Purchase $purchase): void
    {
        $this->purchases->removeElement($purchase);
    }

    public function getPurchases(): Collection
    {
        return $this->purchases;
    }

    public function addGift(\App\Entity\Gift $gift): self
    {
        $this->gifts[] = $gift;
        return $this;
    }

    public function removeGift(\App\Entity\Gift $gift): void
    {
        $this->gifts->removeElement($gift);
    }

    public function getGifts(): Collection
    {
        return $this->gifts;
    }

    public function getConsumedProduct()
    {
        return $this->consumed_product;
    }

    public function setConsumedProduct($product): self
    {
        $this->consumed_product = $product;
        return $this;
    }

    public function addEggRegistryUser(\App\Entity\EggRegistryUser $eggRegistryUser): self
    {
        $this->egg_registry_users[] = $eggRegistryUser;
        return $this;
    }

    public function removeEggRegistryUser(\App\Entity\EggRegistryUser $eggRegistryUser): void
    {
        $this->egg_registry_users->removeElement($eggRegistryUser);
    }

    public function getEggRegistryUsers(): Collection
    {
        return $this->egg_registry_users;
    }

    public function addFowl(\App\Entity\Fowl $fowl): self
    {
        $this->fowls[] = $fowl;
        return $this;
    }

    public function removeFowl(\App\Entity\Fowl $fowl): void
    {
        $this->fowls->removeElement($fowl);
    }

    public function getFowls(): Collection
    {
        return $this->fowls;
    }

    public function addTask(\App\Entity\Task $task): self
    {
        $this->tasks[] = $task;
        return $this;
    }

    public function removeTask(\App\Entity\Task $task): void
    {
        $this->tasks->removeElement($task);
    }

    public function getTasks(): Collection
    {
        return $this->tasks;
    }
}
