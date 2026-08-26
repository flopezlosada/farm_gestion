<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * El tipo de trabajo que pide una oferta de voluntariado: huerta, reparto,
 * obras, oficina, comunicación… Es a la vez la etiqueta de la oferta y lo que
 * cada socix marca en su ficha para decir "avísame de esto".
 *
 * Esa doble función es deliberada y es lo que hace barato el escalado del aviso
 * ({@see VolunteerCall}): cruzar oferta y socix es cruzar dos conjuntos de
 * categorías, sin una segunda taxonomía de "aptitudes" que habría que mantener
 * en paralelo y que nadie rellenaría.
 *
 * Lo que esta entidad NO es: un requisito. Que una oferta sea de categoría
 * "obras" no dice que quien se apunte sepa manejar una desbrozadora; eso lo
 * gobierna {@see VolunteerOffer::$openToAnyone}, que es de la oferta y no del
 * socix. Preferencia (blanda, del socix) y aptitud exigida (dura, de la oferta)
 * son cosas distintas y mezclarlas es lo que convierte un aviso útil en spam.
 *
 * Se retiran con `active = false` en vez de borrarse: una categoría borrada se
 * llevaría por delante el histórico de ofertas que la usaron.
 *
 * @ORM\Table(name="volunteer_category", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_volunteer_category_name", columns={"name"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerCategoryRepository")
 */
class VolunteerCategory
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=80)
     */
    #[Assert\NotBlank(message: 'La categoría necesita un nombre.')]
    #[Assert\Length(max: 80)]
    private string $name = '';

    /**
     * Qué entra en esta categoría, con las palabras de la asociación. Se pinta
     * en la ficha del socix junto a la casilla: quien marca "obras" tiene que
     * saber a qué se está apuntando antes de marcarla.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * Categoría retirada: no se ofrece al crear ofertas nuevas ni aparece en la
     * ficha, pero las ofertas históricas que la usaron siguen enteras.
     *
     * @ORM\Column(type="boolean", options={"default": true})
     */
    private bool $active = true;

    /**
     * Socixs que han pedido que se les avise de esta categoría. Lado inverso:
     * la relación la posee {@see Partner::$volunteerCategories}.
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\Partner", mappedBy="volunteerCategories")
     *
     * @var Collection<int, Partner>
     */
    private Collection $partners;

    public function __construct()
    {
        $this->partners = new ArrayCollection();
    }

    /**
     * El nombre, para pintarla en un desplegable sin pedir el getter.
     */
    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string el nombre de la categoría
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name el nombre de la categoría
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string|null qué entra en esta categoría, o null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description qué entra en esta categoría
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return bool true si la categoría sigue ofreciéndose
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active false para retirarla sin borrar el histórico
     */
    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return Collection<int, Partner> lxs socixs que quieren aviso de esta categoría
     */
    public function getPartners(): Collection
    {
        return $this->partners;
    }
}
