<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un sitio donde se hace voluntariado: la huerta, el local, el invernadero.
 *
 * POR QUÉ UN CATÁLOGO Y NO TEXTO LIBRE. El sitio se escribía a mano en cada
 * tarea, y con turnos eso son cientos de filas: acabas con "la nave", "nave" y
 * "Nave" siendo el mismo sitio, sin poder filtrar ni contar por dónde se
 * trabaja. Son cuatro o cinco sitios en toda la asociación; mantenerlos en una
 * lista cuesta menos que reconciliarlos después.
 *
 * EL MATIZ SIGUE SIENDO LIBRE. El catálogo dice el sitio; "parcela de arriba" o
 * "por la puerta de atrás" va en {@see VolunteerOffer::$placeNote}, porque esos
 * no se repiten y darles ficha sería inventarse entidades.
 *
 * NO SUSTITUYE AL PUNTO DE RECOGIDA ({@see VolunteerOffer::$node}). Aquél es una
 * relación con el reparto de cestas —tiene calendario, grupos y socixs
 * asignados— y es lo que hace que el aviso valga; esto es sólo un nombre de
 * sitio. Una tarea puede tener uno, el otro, o ninguno si se hace desde casa.
 *
 * SE DESACTIVA, NO SE BORRA ({@see $active}): un sitio que ya no se usa tiene
 * tareas viejas colgando, y borrarlo las dejaría sin sitio.
 *
 * @ORM\Table(name="volunteer_place", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_volunteer_place_name", columns={"name"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerPlaceRepository")
 */
class VolunteerPlace
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
    #[Assert\NotBlank(message: 'El sitio necesita un nombre.')]
    #[Assert\Length(max: 80)]
    private string $name = '';

    /**
     * Cómo llegar o qué hay que saber del sitio ("la llave la tiene Marisa",
     * "se aparca en la explanada"). Se enseña con la tarea, así que no hay que
     * repetirlo en cada explicación.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $directions = null;

    /**
     * Si se sigue usando. En false deja de ofrecerse al crear tareas, pero las
     * que ya lo tienen siguen diciendo dónde fueron.
     *
     * @ORM\Column(type="boolean", options={"default": true})
     */
    private bool $active = true;

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string el nombre del sitio
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name el nombre del sitio
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string|null cómo llegar o qué saber del sitio, o null
     */
    public function getDirections(): ?string
    {
        return $this->directions;
    }

    /**
     * @param string|null $directions cómo llegar o qué saber del sitio
     */
    public function setDirections(?string $directions): self
    {
        $this->directions = $directions;

        return $this;
    }

    /**
     * @return bool true si se sigue usando
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active true si se sigue usando
     */
    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return string el nombre, para pintarlo donde se espere un string
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
