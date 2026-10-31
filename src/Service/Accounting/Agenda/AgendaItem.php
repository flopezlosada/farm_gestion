<?php

namespace App\Service\Accounting\Agenda;

/**
 * Una tarea de la portada de contabilidad: qué hay que hacer, por qué, adónde se va
 * a resolverlo y, si se puede, los botones para resolverla ahí mismo. Lleva la ruta y
 * no la URL para que el servicio que la construye no dependa del router y se pueda
 * probar sin HTTP.
 */
final class AgendaItem
{
    /**
     * @param AgendaUrgency        $urgency     Cuánto aprieta.
     * @param string               $title       Qué hay que hacer, en una línea.
     * @param list<string>         $details     Lo que conviene saber antes de ir.
     * @param string               $route       Ruta donde se resuelve.
     * @param array<string, mixed> $routeParams Parámetros de esa ruta.
     * @param string|null          $fragment    Ancla dentro de la página, sin «#».
     * @param list<AgendaAction>   $actions     Lo que se puede resolver desde la propia portada.
     */
    public function __construct(
        public readonly AgendaUrgency $urgency,
        public readonly string $title,
        public readonly array $details,
        public readonly string $route,
        public readonly array $routeParams = [],
        public readonly ?string $fragment = null,
        public readonly array $actions = [],
    ) {
    }
}
