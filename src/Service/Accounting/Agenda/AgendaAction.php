<?php

namespace App\Service\Accounting\Agenda;

/**
 * Un botón de una tarea de la portada: resolverla ahí mismo, sin ir a otra pantalla.
 * Siempre dice qué pasa si se pulsa, porque un «sí» sin saberlo no valida nada.
 *
 * Usa las rutas de siempre (no hay lógica nueva detrás): las POST vuelven a la portada
 * con {@see \App\Http\BackToAgenda}; las GET llevan al formulario de esa tarea.
 */
final class AgendaAction
{
    /**
     * @param string                $label       Lo que pone el botón.
     * @param string                $consequence Qué pasa si se pulsa, en una línea.
     * @param string                $route       Ruta que lo hace.
     * @param array<string, mixed>  $routeParams Parámetros de esa ruta.
     * @param string|null           $csrfId      Id del token CSRF si es un POST; null si es un enlace.
     * @param array<string, scalar> $fields      Campos ocultos que lleva el POST.
     * @param bool                  $primary     Si es la acción principal (aceptar) o la secundaria (descartar).
     */
    private function __construct(
        public readonly string $label,
        public readonly string $consequence,
        public readonly string $route,
        public readonly array $routeParams,
        public readonly ?string $csrfId,
        public readonly array $fields,
        public readonly bool $primary,
    ) {
    }

    /**
     * Un botón que hace algo (POST con su token) y vuelve a la portada.
     *
     * @param array<string, mixed>  $routeParams
     * @param array<string, scalar> $fields
     */
    public static function post(string $label, string $consequence, string $route, array $routeParams, string $csrfId, array $fields = [], bool $primary = true): self
    {
        return new self($label, $consequence, $route, $routeParams, $csrfId, $fields, $primary);
    }

    /**
     * Un enlace al formulario donde se termina (cuando hay que revisar datos antes).
     *
     * @param array<string, mixed> $routeParams
     */
    public static function link(string $label, string $consequence, string $route, array $routeParams = []): self
    {
        return new self($label, $consequence, $route, $routeParams, null, [], true);
    }

    /** Si es un POST (formulario con token) y no un enlace. */
    public function isPost(): bool
    {
        return $this->csrfId !== null;
    }
}
