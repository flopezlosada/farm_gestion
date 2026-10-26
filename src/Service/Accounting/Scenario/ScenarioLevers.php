<?php

namespace App\Service\Accounting\Scenario;

use App\Entity\WorkerSalary;

/**
 * Lo que cambia un escenario respecto al presupuesto del que parte. Cada palanca a
 * null deja lo presupuestado; puesta, SUSTITUYE lo presupuestado en los meses que
 * faltan. Los puntuales no sustituyen nada: se suman.
 */
final class ScenarioLevers
{
    /**
     * @param list<non-empty-list<WorkerSalary>>|null $staff los tramos de sueldo de cada
     *                                                       persona, de verdad o imaginaria;
     *                                                       null = los sueldos del presupuesto
     * @param list<OneOff>                            $oneOffs
     */
    public function __construct(
        public readonly ?BasketFees $fees = null,
        public readonly ?MaintenanceFee $maintenance = null,
        public readonly ?array $staff = null,
        public readonly array $oneOffs = [],
    ) {
    }

    /** ¿Cambia algo? Sin palancas el escenario es el presupuesto tal cual. */
    public function isEmpty(): bool
    {
        return $this->fees === null && $this->maintenance === null && $this->staff === null && $this->oneOffs === [];
    }
}
