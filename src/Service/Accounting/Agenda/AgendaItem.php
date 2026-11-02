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
     * @param string|null          $identity    Lo que distingue la tarea en lugar del título,
     *                                          cuando éste lleva un dato que cambia sin que
     *                                          cambie la tarea (hasta dónde llega el libro).
     * @param \DateTimeImmutable|null $alertedOn Cuándo se avisó ya de esta tarea por otro
     *                                          camino (la notificación oficial avisa al
     *                                          llegar): la vigilancia cuenta desde ahí y
     *                                          no repite el aviso del mismo día.
     */
    public function __construct(
        public readonly AgendaUrgency $urgency,
        public readonly string $title,
        public readonly array $details,
        public readonly string $route,
        public readonly array $routeParams = [],
        public readonly ?string $fragment = null,
        public readonly array $actions = [],
        public readonly ?string $identity = null,
        public readonly ?\DateTimeImmutable $alertedOn = null,
    ) {
    }

    /**
     * Lo que identifica la tarea entre un día y el siguiente, para no avisar dos veces
     * de lo mismo: adónde se va, con qué parámetros, y el título. El título entra a
     * propósito: cuando cambia (el plazo de la gestoría pasa de «acaba el 5» a «se
     * pasó»), la tarea ya no es la misma y merece aviso nuevo. Si el título lleva algo
     * que cambia sin que la tarea cambie, la tarea da su identidad y el título no entra.
     *
     * @return string Huella de 40 caracteres.
     */
    public function key(): string
    {
        return sha1(json_encode([$this->route, $this->routeParams, $this->fragment, $this->identity ?? $this->title], JSON_THROW_ON_ERROR));
    }
}
