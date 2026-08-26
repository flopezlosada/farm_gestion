<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * La suscripción de UN navegador a los avisos push de una persona: la URL del
 * servicio de push al que hay que mandar el mensaje, más las dos claves que el
 * navegador nos dio para cifrárselo (`p256dh` y `auth`).
 *
 * Una persona tiene varias: el móvil, el portátil, el ordenador de casa. Por eso
 * cuelga de {@see User} y no al revés, y por eso el envío recorre todas.
 *
 * EL ENDPOINT ES LA CLAVE NATURAL, de ahí el unique: un navegador que vuelve a
 * suscribirse manda el mismo endpoint, así que re-suscribirse actualiza la fila
 * en vez de apilar duplicados que multiplicarían el mismo aviso.
 *
 * LAS FILAS SE PODAN SOLAS. Cuando el servicio de push responde 404 o 410, esa
 * suscripción ya no existe (navegador desinstalado, permiso revocado, caducada)
 * y {@see \App\Service\Push\PushSender} la borra. Sin esa poda, cada envío
 * arrastraría para siempre a los dispositivos muertos.
 *
 * Cuelga de User y no de Partner a propósito: quien se suscribe es un navegador
 * con sesión iniciada, y hay Users que no son socixs (gestión, trabajadoras).
 *
 * @ORM\Table(name="push_subscription", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_push_subscription_endpoint", columns={"endpoint"})
 * }, indexes={
 *     @ORM\Index(name="idx_push_subscription_user", columns={"user_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\PushSubscriptionRepository")
 */
class PushSubscription
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?User $user = null;

    /**
     * La URL a la que hay que hacer POST del mensaje cifrado. La pone el
     * navegador y es específica de cada uno y de su servicio de push.
     *
     * Larga a propósito: los endpoints de FCM y compañía pasan holgadamente de
     * los 255 caracteres, y truncarlos rompería el envío sin dar un error claro.
     *
     * @ORM\Column(type="string", length=500)
     */
    private string $endpoint = '';

    /**
     * Clave pública del navegador (Base64URL), con la que se cifra el mensaje.
     *
     * @ORM\Column(type="string", length=255)
     */
    private string $p256dh = '';

    /**
     * Secreto de autenticación del navegador (Base64URL), parte del cifrado.
     *
     * @ORM\Column(type="string", length=255)
     */
    private string $auth = '';

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return User|null de quién es este navegador
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * @param User|null $user de quién es este navegador
     */
    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return string la URL del servicio de push
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * @param string $endpoint la URL del servicio de push
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * @return string la clave pública del navegador
     */
    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    /**
     * @param string $p256dh la clave pública del navegador
     */
    public function setP256dh(string $p256dh): self
    {
        $this->p256dh = $p256dh;

        return $this;
    }

    /**
     * @return string el secreto de autenticación del navegador
     */
    public function getAuth(): string
    {
        return $this->auth;
    }

    /**
     * @param string $auth el secreto de autenticación del navegador
     */
    public function setAuth(string $auth): self
    {
        $this->auth = $auth;

        return $this;
    }

    /**
     * @return \DateTimeInterface cuándo se suscribió este navegador
     */
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
