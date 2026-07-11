<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una tarjeta de oferta formativa de la portada del LAR (inmersión pedagógica,
 * aproximación, visita…). Pertenece al singleton {@see LarPage} y se muestra en
 * el bloque "Formación e inmersión pedagógica" de /lar.
 *
 * Es entidad propia (no campos fijos en LarPage) para que la coordinación pueda
 * tener tantas ofertas como quiera —dos, tres o cinco— y reordenarlas, sin tocar
 * código ni esquema.
 *
 * @ORM\Table(name="lar_offer")
 * @ORM\Entity(repositoryClass="App\Repository\LarOfferRepository")
 */
class LarOffer
{
    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Portada a la que pertenece.
     *
     * @var LarPage|null
     * @ORM\ManyToOne(targetEntity="App\Entity\LarPage", inversedBy="offers")
     * @ORM\JoinColumn(name="page_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private $page;

    /**
     * @var string|null
     * @ORM\Column(name="title", type="string", length=150, nullable=true)
     */
    private $title;

    /**
     * Duración/periodicidad de la oferta (p. ej. "50 h · de lunes a viernes").
     *
     * @var string|null
     * @ORM\Column(name="hours", type="string", length=150, nullable=true)
     */
    private $hours;

    /**
     * @var string|null
     * @ORM\Column(name="body", type="text", nullable=true)
     */
    private $body;

    /**
     * Orden en el bloque de oferta (menor = antes).
     *
     * @var int
     * @ORM\Column(name="position", type="integer", options={"default": 0})
     */
    private $position = 0;

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return LarPage|null
     */
    public function getPage(): ?LarPage
    {
        return $this->page;
    }

    /**
     * @param LarPage|null $page
     * @return self
     */
    public function setPage(?LarPage $page): self
    {
        $this->page = $page;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * @param string|null $title
     * @return self
     */
    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getHours(): ?string
    {
        return $this->hours;
    }

    /**
     * @param string|null $hours
     * @return self
     */
    public function setHours(?string $hours): self
    {
        $this->hours = $hours;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * @param string|null $body
     * @return self
     */
    public function setBody(?string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * @return int
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * @param int $position
     * @return self
     */
    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }
}
