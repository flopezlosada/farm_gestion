<?php

namespace App\Service\Cron;

/**
 * Qué tarea programada está corriendo ahora mismo, si es que hay alguna.
 *
 * Existe para una sola cosa: que la bitácora de avisos
 * ({@see \App\Entity\NotificationLog}) pueda decir de qué ejecución salió cada
 * correo. Sin esto, el registro contesta "se mandó el martes a las nueve" pero
 * no "salió de esta pasada del recordatorio", que es como se navega de una
 * tarea a sus efectos — y al revés, de un correo raro a la ejecución que lo
 * produjo.
 *
 * La alternativa era pasar la ejecución en curso de mano en mano desde el
 * comando hasta el transporte de correo, atravesando mailers, notificadores y
 * el guardián de idempotencia. Serían firmas cambiadas en una docena de clases
 * para un dato que sólo interesa al registro, y cada aviso nuevo tendría que
 * acordarse de arrastrarlo.
 *
 * SÍ, ES ESTADO MUTABLE EN UN SERVICIO, que normalmente sería mala idea. Aquí
 * es correcto porque el ámbito coincide con el proceso: un comando de consola
 * ejecuta una tarea de cada vez, y {@see \App\Command\AbstractCronCommand} la
 * abre y la cierra en un try/finally. En una petición web nadie la abre, así
 * que queda vacío y los avisos que dispara una persona se registran sin tarea,
 * que es la verdad. El único caso con dos tareas seguidas en el mismo proceso
 * es el tick horario, y ahí van una detrás de otra, no a la vez.
 */
class CronRunContext
{
    private ?string $taskKey = null;

    private ?int $runId = null;

    /**
     * Declara que empieza a ejecutarse una tarea.
     *
     * @param string   $taskKey Clave en el manifiesto ("cron.pickup_reminder").
     * @param int|null $runId   Id de la fila de `cron_run`, si se pudo abrir.
     */
    public function enter(string $taskKey, ?int $runId): void
    {
        $this->taskKey = $taskKey;
        $this->runId = $runId;
    }

    /**
     * Declara que la tarea ha terminado. Va en un `finally`: si no se limpiara,
     * los avisos que mandara después el mismo proceso se atribuirían a una
     * tarea que ya no está corriendo.
     */
    public function leave(): void
    {
        $this->taskKey = null;
        $this->runId = null;
    }

    /** Clave de la tarea en curso, o null si esto no viene de una tarea. */
    public function taskKey(): ?string
    {
        return $this->taskKey;
    }

    /** Id de la ejecución en curso, o null. */
    public function runId(): ?int
    {
        return $this->runId;
    }
}
