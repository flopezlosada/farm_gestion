<?php

namespace App\Service\Cron;

/**
 * Cómo se lanza una tarea programada a mano desde la web.
 *
 * Son tres modos y no dos booleanos a propósito: "previsualizar forzando" no
 * significa nada, y con dos flags sueltos esa combinación sería representable.
 * Cada pantalla elige el suyo y se lee en la llamada.
 */
enum CronRunMode
{
    /** No toca nada: lista lo que haría (--dry-run). No cuenta como ejecución. */
    case Preview;

    /**
     * Ejecuta EXACTAMENTE como lo haría el reloj: si el interruptor de la tarea
     * está apagado, no se ejecuta. Es lo que quiere la pantalla de diagnóstico
     * de envíos, que existe para comprobar qué haría el cron.
     */
    case AsScheduled;

    /**
     * Ejecuta saltando el interruptor propio de la tarea (--force). Es el puente
     * manual de /gestion/settings mientras el reloj esté caído: congelar el
     * listado un lunes aunque la tarea programada esté pausada. NUNCA salta los
     * interruptores de entrega (`requires`).
     */
    case Forced;

    /**
     * ¿Es una previsualización sin efectos?
     */
    public function isPreview(): bool
    {
        return $this === self::Preview;
    }
}
