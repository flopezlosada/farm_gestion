<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Agrupación de partidas: ADMINISTRACIÓN, HUERTA, SUELDOS, HUEVOS, VARIOS…
 *
 * Es la unidad con la que se COMPARA lo presupuestado y lo real. La granularidad de
 * las partidas cambia con los años —un año los sueldos son una línea y al siguiente
 * se abren en nómina, seguridad social e IRPF—, y comparar a ese nivel rompería la
 * serie histórica en cuanto alguien añade una partida. El grupo cuadra siempre.
 *
 * Existe como entidad propia, en vez de como una partida con padre, para que un
 * apunte NO pueda colgar de un grupo: si grupo y partida fuesen la misma tabla habría
 * que prohibirlo con una validación, y una validación se puede saltar.
 *
 * @ORM\Table(name="budget_category_group")
 * @ORM\Entity(repositoryClass="App\Repository\BudgetCategoryGroupRepository")
 */
class BudgetCategoryGroup
{
    /** Dinero que entra por la actividad ordinaria: cuotas, cursos, ventas. */
    public const KIND_INCOME = 1;

    /** Dinero que sale por la actividad ordinaria: sueldos, insumos, local. */
    public const KIND_EXPENSE = 2;

    /** Compra de algo que dura: maquinaria, obra, gallinas. */
    public const KIND_INVESTMENT = 3;

    /** Préstamos recibidos y sus cuotas de devolución. */
    public const KIND_FINANCING = 4;

    /**
     * Movimiento de dinero entre dos cuentas propias. No es ingreso ni gasto: la
     * asociación no es más rica ni más pobre, así que los informes lo excluyen. Si no
     * se separase, sacar 500 € del banco para la caja contaría como gasto de 500 € y
     * como ingreso de 500 €, inflando los dos totales.
     */
    public const KIND_TRANSFER = 5;

    /** Etiquetas de los tipos, para formularios y cabeceras de informe. */
    public const KIND_LABELS = [
        self::KIND_INCOME => 'Ingresos',
        self::KIND_EXPENSE => 'Gastos',
        self::KIND_INVESTMENT => 'Inversión',
        self::KIND_FINANCING => 'Financiación',
        self::KIND_TRANSFER => 'Traspasos',
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    /**
     * @ORM\Column(type="smallint")
     */
    #[Assert\Choice(callback: 'kindValues')]
    private int $kind = self::KIND_EXPENSE;

    /**
     * @ORM\Column(name="sort_order", type="smallint")
     */
    private int $sortOrder = 0;

    /**
     * @ORM\OneToMany(targetEntity="BudgetCategory", mappedBy="group")
     * @ORM\OrderBy({"sortOrder" = "ASC", "name" = "ASC"})
     *
     * @var Collection<int, BudgetCategory>
     */
    private Collection $categories;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
    }

    /** Valores válidos de {@see getKind}, para la validación del formulario. */
    public static function kindValues(): array
    {
        return array_keys(self::KIND_LABELS);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getKind(): int
    {
        return $this->kind;
    }

    public function setKind(int $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    /** Etiqueta legible del tipo de grupo. */
    public function getKindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? '';
    }

    /**
     * Si los apuntes de este grupo cuentan como actividad (ingreso, gasto, inversión o
     * financiación) o son simple movimiento interno de dinero.
     */
    public function isTransfer(): bool
    {
        return $this->kind === self::KIND_TRANSFER;
    }

    /**
     * Signo que se espera de los apuntes del grupo: +1 si el dinero entra, -1 si sale,
     * 0 si puede ser cualquiera. Los traspasos y la financiación admiten los dos: un
     * traspaso tiene dos mitades, y en financiación entra el préstamo y salen las
     * cuotas. El resto tiene un signo natural, y un apunte con el contrario es casi
     * siempre una devolución o un abono legítimo, no un error: por eso orienta los
     * formularios pero no se impone como validación.
     */
    public function expectedSign(): int
    {
        return match ($this->kind) {
            self::KIND_INCOME => 1,
            self::KIND_EXPENSE, self::KIND_INVESTMENT => -1,
            default => 0,
        };
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

    /** @return Collection<int, BudgetCategory> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
