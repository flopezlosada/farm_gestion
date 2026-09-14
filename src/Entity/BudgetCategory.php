<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partida a la que se imputa un apunte: «Semillas y plantel», «Nóminas», «Cuotas de
 * socixs»… Es la columna que en el Excel se llama «CUENTA» y se teclea a mano, de ahí
 * que hoy convivan `TRASPASO` y `TRAPASO`, o `LA CERRADA`, `LA CEERADA` y `CERRADA`.
 *
 * El nombre es único DENTRO de su grupo, no en toda la tabla: «Formación» es una
 * partida de ingresos (lo que se cobra por los cursos) y otra de gastos (lo que se
 * paga a quien los da). Son dos hechos económicos distintos que el presupuesto ya
 * presenta en dos líneas, y sumarlos en una sola escondería los dos.
 *
 * @ORM\Table(name="budget_category", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_budget_category_group_name", columns={"group_id", "name"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\BudgetCategoryRepository")
 */
#[UniqueEntity(fields: ['group', 'name'], message: 'Ese grupo ya tiene una partida con ese nombre.', errorPath: 'name')]
class BudgetCategory
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * RESTRICT: un grupo con partidas no se borra. Mover una partida de grupo cambia
     * dónde suma en todos los años, así que es una decisión consciente, no un efecto
     * colateral de borrar.
     *
     * @ORM\ManyToOne(targetEntity="BudgetCategoryGroup", inversedBy="categories")
     * @ORM\JoinColumn(name="group_id", nullable=false, onDelete="RESTRICT")
     */
    #[Assert\NotNull]
    private ?BudgetCategoryGroup $group = null;

    /**
     * @ORM\Column(type="string", length=100)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    /**
     * Para qué es esta partida y qué NO va aquí. El sitio donde dejar escrito lo que
     * hoy sólo sabe quien lleva las cuentas.
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $description = null;

    /**
     * Una partida retirada deja de ofrecerse al anotar, pero conserva sus apuntes y
     * sigue apareciendo en los informes de los años en que se usó.
     * @ORM\Column(name="is_active", type="boolean")
     */
    private bool $active = true;

    /**
     * @ORM\Column(name="sort_order", type="smallint")
     */
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): ?BudgetCategoryGroup
    {
        return $this->group;
    }

    public function setGroup(?BudgetCategoryGroup $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /** Tipo heredado del grupo: ingreso, gasto, inversión, financiación o traspaso. */
    public function getKind(): ?int
    {
        return $this->group?->getKind();
    }

    /** Si la partida mueve dinero entre cuentas propias en vez de con el exterior. */
    public function isTransfer(): bool
    {
        return $this->group !== null && $this->group->isTransfer();
    }

    /**
     * Nombre completo con su grupo, para desplegables y listados donde el nombre suelto
     * sería ambiguo («Formación» aparece dos veces, en ingresos y en gastos).
     */
    public function getQualifiedName(): string
    {
        return $this->group !== null ? $this->group->getName().' › '.$this->name : $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
