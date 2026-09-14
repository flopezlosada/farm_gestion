<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lo previsto para una partida en un mes concreto de un presupuesto.
 *
 * Una fila por partida y mes, en vez de una fila por partida con doce columnas: así el
 * seguimiento compara contra los apuntes con una sola consulta agrupada, y añadir una
 * partida no cambia la forma de la tabla. Son unas 360 filas por año.
 *
 * El importe lleva el MISMO signo que los apuntes ({@see AccountEntry}): negativo lo
 * que sale. Así comparar previsto y real es restar, sin acordarse de invertir nada
 * por el camino. El formulario aplica el signo del grupo, de modo que se sigue
 * tecleando en positivo.
 *
 * @ORM\Table(name="budget_line", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_budget_line_budget_category_month", columns={"budget_id", "category_id", "month"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\BudgetLineRepository")
 */
class BudgetLine
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Budget", inversedBy="lines")
     * @ORM\JoinColumn(name="budget_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Budget $budget = null;

    /**
     * RESTRICT: una partida presupuestada no se borra. Si deja de usarse se desactiva,
     * y el presupuesto de los años en que existió sigue siendo legible.
     *
     * @ORM\ManyToOne(targetEntity="BudgetCategory")
     * @ORM\JoinColumn(name="category_id", nullable=false, onDelete="RESTRICT")
     */
    #[Assert\NotNull]
    private ?BudgetCategory $category = null;

    /**
     * Mes del año, de 1 a 12.
     * @ORM\Column(type="smallint")
     */
    #[Assert\Range(min: 1, max: 12)]
    private int $month = 1;

    /**
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    #[Assert\NotNull]
    private string $amount = '0';

    public function __construct(?Budget $budget = null, ?BudgetCategory $category = null, int $month = 1, string $amount = '0')
    {
        $this->budget = $budget;
        $this->category = $category;
        $this->month = $month;
        $this->amount = $amount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudget(): ?Budget
    {
        return $this->budget;
    }

    public function setBudget(?Budget $budget): self
    {
        $this->budget = $budget;

        return $this;
    }

    public function getCategory(): ?BudgetCategory
    {
        return $this->category;
    }

    public function setCategory(?BudgetCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): self
    {
        $this->month = $month;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }
}
