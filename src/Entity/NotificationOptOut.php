<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un aviso que un socix NO quiere recibir por un canal concreto.
 *
 * SE GUARDA LO QUE SE APAGA, NO LO QUE SE ENCIENDE, y es la decisión de diseño
 * de la clase. Hoy todo el mundo recibe todo; si la tabla guardase los "sí",
 * habría que sembrar una fila por socix, tema y canal —y el día que se añada un
 * tema nuevo, otra tanda— o los avisos dejarían de salir en silencio para toda
 * la asociación. Sin fila = lo quiere, que es el estado por defecto y el que ya
 * tienen los 246.
 *
 * La clave es (socix, tema, canal): quien no quiere el correo de la cesta pero
 * sí el aviso al móvil tiene una sola fila. El catálogo de temas y de qué
 * canales usa cada uno vive en {@see \App\Service\Notification\NotificationTopic};
 * aquí sólo se guardan cadenas, para que añadir un tema no obligue a migrar
 * esta tabla.
 *
 * NO SUSTITUYE A `Partner::volunteering_opt_out`, que es otra cosa: aquél dice
 * "no me cuentes para el voluntariado" y saca a la persona de las audiencias
 * ({@see \App\Repository\PartnerRepository}); éste dice "cuéntame, pero no me
 * avises por aquí".
 *
 * @ORM\Table(name="notification_opt_out", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_notification_opt_out", columns={"partner_id", "topic", "channel"})
 * }, indexes={
 *     @ORM\Index(name="idx_notification_opt_out_partner", columns={"partner_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\NotificationOptOutRepository")
 */
class NotificationOptOut
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Partner")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private Partner $partner;

    /**
     * Clave del tema, de las declaradas en NotificationTopic::TOPICS.
     *
     * @ORM\Column(type="string", length=32)
     */
    private string $topic;

    /**
     * Canal: 'email' o 'push'.
     *
     * @ORM\Column(type="string", length=16)
     */
    private string $channel;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function setPartner(Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setTopic(string $topic): self
    {
        $this->topic = $topic;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
