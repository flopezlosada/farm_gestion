<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de UNA ejecución de una tarea programada.
 *
 * Existe porque hasta ahora nada en el sistema vigilaba que las tareas
 * corrieran: entre el 20 de julio y el 4 de agosto de 2026 ninguna se ejecutó
 * en producción y nadie se enteró (el crontab vive en un hosting sin SSH, sin
 * panel y sin aviso al fallar). Con una fila por ejecución, /gestion/settings
 * puede decir por cada tarea cuándo corrió por última vez y qué pasó.
 *
 * Se escribe desde {@see \App\Command\AbstractCronCommand}, así que registra
 * IGUAL las ejecuciones del cron por consola y las manuales desde la web: si
 * solo registrara la web, una caída del cron seguiría siendo invisible.
 *
 * Cuatro estados, no dos ({@see self::STATUS_DISABLED} … {@see self::STATUS_FAILED}):
 * "apagada por configuración" y "corrió sin encontrar trabajo" son situaciones
 * sanas pero distintas, y ninguna de las dos es "hizo su trabajo". Sin esa
 * distinción, el chequeo de salud o llena de falsas alarmas o oculta caídas
 * reales.
 *
 * @ORM\Table(
 *     name="cron_run",
 *     indexes={@ORM\Index(name="IDX_cron_run_task_started", columns={"task_key", "started_at"})}
 * )
 * @ORM\Entity(repositoryClass="App\Repository\CronRunRepository")
 */
class CronRun
{
    /** Apagada por configuración: no llegó a ejecutarse. No es un fallo. */
    public const STATUS_DISABLED = 'disabled';

    /** Se ejecutó y no había trabajo que hacer. Es un resultado sano. */
    public const STATUS_NOTHING_TO_DO = 'nothing_to_do';

    /** Se ejecutó e hizo trabajo. */
    public const STATUS_DONE = 'done';

    /**
     * Falló. También es el estado con el que nace toda ejecución: así, un
     * proceso que muere sin cerrar su fila (timeout, kill, OOM) queda como
     * fallo con `finished_at` a NULL, en vez de desaparecer del registro.
     */
    public const STATUS_FAILED = 'failed';

    /** Lanzada por el reloj (consola / cron del hosting). */
    public const TRIGGER_SCHEDULE = 'schedule';

    /** Lanzada a mano por alguien desde /gestion/settings. */
    public const TRIGGER_MANUAL = 'manual';

    /**
     * Tope de caracteres de salida que se persisten. La columna es TEXT, pero
     * guardar la salida entera de un comando verboso no aporta nada y engorda
     * la tabla sin límite.
     */
    public const OUTPUT_MAX_LENGTH = 8000;

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * Clave de la tarea en el manifiesto {@see \App\Service\AppSettings::CRONS},
     * p. ej. "cron.pickup_reminder".
     *
     * @ORM\Column(name="task_key", type="string", length=100)
     */
    private string $taskKey = '';

    /**
     * Nombre del comando de consola ejecutado, p. ej. "app:send-pickup-reminders".
     * Redundante con la clave, pero se guarda para que el registro siga siendo
     * legible si el manifiesto cambia.
     *
     * @ORM\Column(name="command", type="string", length=120)
     */
    private string $command = '';

    /**
     * Uno de los cuatro estados: disabled | nothing_to_do | done | failed.
     *
     * @ORM\Column(name="status", type="string", length=20)
     */
    private string $status = self::STATUS_FAILED;

    /**
     * Origen del disparo: schedule (el reloj) | manual (una persona). Sin este
     * dato la pantalla mentiría — "corrió el lunes" cuando en realidad alguien
     * lo lanzó a mano porque el cron estaba caído.
     *
     * @ORM\Column(name="trigger_source", type="string", length=20)
     */
    private string $triggerSource = self::TRIGGER_SCHEDULE;

    /**
     * @ORM\Column(name="started_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $startedAt;

    /**
     * NULL = la ejecución no llegó a cerrarse (proceso muerto a mitad).
     *
     * @ORM\Column(name="finished_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * Código de salida del comando, NULL mientras no termina.
     *
     * @ORM\Column(name="exit_code", type="integer", nullable=true)
     */
    private ?int $exitCode = null;

    /**
     * Resumen de una línea de lo ocurrido, para pintarlo en la pantalla sin
     * tener que abrir la salida completa ("interruptor apagado", "0
     * destinatarios", el mensaje de la excepción…).
     *
     * @ORM\Column(name="detail", type="string", length=255, nullable=true)
     */
    private ?string $detail = null;

    /**
     * Salida del comando, recortada a {@see self::OUTPUT_MAX_LENGTH}. Vale
     * tanto para las ejecuciones manuales como para las del cron: en un hosting
     * solo-FTP, verla aquí ahorra bajarse var/log/cron.log.
     *
     * @ORM\Column(name="output", type="text", nullable=true)
     */
    private ?string $output = null;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    /**
     * @return int|null Identificador autogenerado.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string Clave de la tarea en el manifiesto.
     */
    public function getTaskKey(): string
    {
        return $this->taskKey;
    }

    /**
     * @param string $taskKey Clave de la tarea en el manifiesto.
     */
    public function setTaskKey(string $taskKey): self
    {
        $this->taskKey = $taskKey;
        return $this;
    }

    /**
     * @return string Nombre del comando ejecutado.
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @param string $command Nombre del comando ejecutado.
     */
    public function setCommand(string $command): self
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @return string Estado de la ejecución (uno de los cuatro STATUS_*).
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status Uno de los cuatro STATUS_*.
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return string Origen del disparo (TRIGGER_SCHEDULE|TRIGGER_MANUAL).
     */
    public function getTriggerSource(): string
    {
        return $this->triggerSource;
    }

    /**
     * @param string $triggerSource TRIGGER_SCHEDULE o TRIGGER_MANUAL.
     */
    public function setTriggerSource(string $triggerSource): self
    {
        $this->triggerSource = $triggerSource;
        return $this;
    }

    /**
     * @return \DateTimeImmutable Instante en que arrancó la ejecución.
     */
    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * @param \DateTimeImmutable $startedAt Instante de arranque.
     */
    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    /**
     * @return \DateTimeImmutable|null Instante de cierre, o null si no cerró.
     */
    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @param \DateTimeImmutable|null $finishedAt Instante de cierre.
     */
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    /**
     * @return int|null Código de salida, o null si no terminó.
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * @param int|null $exitCode Código de salida del comando.
     */
    public function setExitCode(?int $exitCode): self
    {
        $this->exitCode = $exitCode;
        return $this;
    }

    /**
     * @return string|null Resumen de una línea, o null.
     */
    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * Guarda el resumen recortándolo al largo de la columna, para que un
     * mensaje de excepción largo no reviente el INSERT.
     *
     * @param string|null $detail Resumen de una línea.
     */
    public function setDetail(?string $detail): self
    {
        $this->detail = $detail === null ? null : mb_substr(trim($detail), 0, 255);
        return $this;
    }

    /**
     * @return string|null Salida recortada del comando, o null.
     */
    public function getOutput(): ?string
    {
        return $this->output;
    }

    /**
     * Guarda la salida recortada a {@see self::OUTPUT_MAX_LENGTH}, quedándose
     * con el FINAL: el resumen del comando y la traza de un fallo salen al
     * final, no al principio.
     *
     * @param string|null $output Salida completa del comando.
     */
    public function setOutput(?string $output): self
    {
        $output = $output === null ? null : trim($output);

        if ($output !== null && mb_strlen($output) > self::OUTPUT_MAX_LENGTH) {
            $output = "…(salida recortada)\n" . mb_substr($output, -self::OUTPUT_MAX_LENGTH);
        }

        $this->output = $output;
        return $this;
    }

    /**
     * ¿Terminó la ejecución? Una fila sin cierre es un proceso que murió a
     * mitad.
     */
    public function isFinished(): bool
    {
        return $this->finishedAt !== null;
    }

    /**
     * Duración en segundos, o null si no terminó.
     */
    public function getDurationSeconds(): ?int
    {
        return $this->finishedAt === null
            ? null
            : $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
