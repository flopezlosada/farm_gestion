<?php

namespace App\Command;

use App\Entity\CronRun;
use App\Service\Cron\CronRunLogger;
use App\Service\Cron\CronTaskRegistry;
use App\Service\Cron\TeeOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base de las tareas programadas: aplica el gate de interruptores y registra la
 * ejecución. Las clases hijas implementan sólo su trabajo, en {@see self::doExecute()}.
 *
 * POR QUÉ AQUÍ Y NO EN EL RUNNER DE LA WEB. El gate y el registro tienen que
 * cubrir el camino que de verdad falla: el cron del hosting ejecuta
 * `bin/console app:...` por consola y nunca pasa por la web. Puestos en el
 * runner, los interruptores dejarían de inhibir el cron real y el registro sólo
 * vería los lanzamientos manuales — es decir, la caída del 20 de julio de 2026
 * habría seguido siendo invisible. Puestos aquí, valen para los tres caminos:
 * consola, ejecución manual desde /gestion/settings y, cuando llegue, el tick.
 *
 * `execute()` es final a propósito: un comando de cron que se saltara el gate o
 * el registro sería un agujero silencioso, y con la plantilla cerrada eso no se
 * puede escribir por descuido.
 *
 * Contrato de las opciones, que se mantiene tal cual estaba:
 *
 * - `--dry-run`: previsualiza. NO pasa por el gate y NO se registra: una
 *   previsualización no es una ejecución y no debe contar como "la última vez
 *   que corrió".
 * - `--force`: salta el interruptor propio de la tarea (es lo que permite
 *   congelar el listado a mano un lunes con el cron caído) pero NO los de
 *   entrega declarados en `requires`.
 *
 * Quién lanzó la ejecución es un eje DISTINTO de `--force` y no se deduce de él:
 * lo declara {@see self::markLaunchedByHand()}, que llama {@see \App\Service\Cron\CronRunner}.
 * Si se dedujera de `--force`, una tarea lanzada desde la pantalla de
 * diagnóstico (que ejecuta sin forzar, como lo haría el reloj) se registraría
 * como si la hubiera disparado el reloj, y la pantalla daría por vivo un
 * planificador parado.
 */
abstract class AbstractCronCommand extends Command
{
    protected CronTaskRegistry $cronTasks;

    protected CronRunLogger $cronRunLogger;

    /** Estado reportado por la hija ({@see self::nothingToDo()} / {@see self::didWork()}). */
    private ?string $reportedStatus = null;

    /** Resumen de una línea reportado por la hija. */
    private ?string $reportedDetail = null;

    /** ¿La lanzó una persona desde la web, en vez del reloj? */
    private bool $launchedByHand = false;

    /**
     * Declara que esta ejecución la ha pedido una persona. Lo llama el runner de
     * la web antes de ejecutar; el cron por consola no lo llama, así que su
     * ejecución queda registrada como del reloj.
     */
    public function markLaunchedByHand(): void
    {
        $this->launchedByHand = true;
    }

    /**
     * Dependencias del andamiaje (gate y registro), inyectadas por setter para
     * que las hijas no tengan que arrastrarlas en su constructor: no son
     * dependencias de su dominio, y así añadir una tarea nueva no obliga a
     * recordar nada.
     *
     * @param CronTaskRegistry $cronTasks     Lectura del manifiesto de tareas.
     * @param CronRunLogger    $cronRunLogger Escritura del registro de ejecuciones.
     */
    #[Required]
    public function setCronScaffolding(CronTaskRegistry $cronTasks, CronRunLogger $cronRunLogger): void
    {
        $this->cronTasks = $cronTasks;
        $this->cronRunLogger = $cronRunLogger;
    }

    /**
     * El trabajo de la tarea. Debe devolver un código de salida de consola;
     * cuando no había nada que hacer, devolver {@see self::nothingToDo()} para
     * que el registro lo distinga de haber trabajado.
     *
     * @param InputInterface  $input  Entrada del comando.
     * @param OutputInterface $output Salida (ya interceptada para el registro).
     */
    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;

    /**
     * Marca la ejecución como "corrió y no había trabajo" y devuelve éxito. No
     * es un fallo: es el resultado sano de una tarea que vigila algo que hoy no
     * ha ocurrido.
     *
     * @param string $detail Resumen de una línea ("sin Baskets pendientes").
     */
    protected function nothingToDo(string $detail): int
    {
        $this->reportedStatus = CronRun::STATUS_NOTHING_TO_DO;
        $this->reportedDetail = $detail;

        return Command::SUCCESS;
    }

    /**
     * Marca la ejecución como "corrió e hizo trabajo" y devuelve éxito.
     *
     * @param string $detail Resumen de una línea ("14 recordatorios enviados").
     */
    protected function didWork(string $detail): int
    {
        $this->reportedStatus = CronRun::STATUS_DONE;
        $this->reportedDetail = $detail;

        return Command::SUCCESS;
    }

    /**
     * Plantilla de ejecución: resuelve la tarea en el manifiesto, aplica el
     * gate, registra el arranque, delega en la hija y cierra el registro con el
     * resultado.
     *
     * @param InputInterface  $input  Entrada del comando.
     * @param OutputInterface $output Salida real.
     */
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Los comandos son servicios de un solo ejemplar: si el mismo proceso
        // ejecuta esta tarea dos veces, la segunda heredaría lo que reportó la
        // primera y se registraría un resultado que no es el suyo. Se limpia al
        // entrar, y el origen del disparo al salir (lo marca quien lanza, antes
        // de llegar aquí).
        $this->reportedStatus = null;
        $this->reportedDetail = null;

        try {
            return $this->runTask($input, $output);
        } finally {
            $this->launchedByHand = false;
        }
    }

    /**
     * El cuerpo de {@see self::execute()}, separado sólo para que la limpieza de
     * estado quede en un `finally` sin anidar todo el método.
     *
     * @param InputInterface  $input  Entrada del comando.
     * @param OutputInterface $output Salida real.
     */
    private function runTask(InputInterface $input, OutputInterface $output): int
    {
        $task = $this->cronTasks->findByCommand((string) $this->getName());
        if ($task === null) {
            throw new \LogicException(sprintf(
                'El comando "%s" hereda de AbstractCronCommand pero no está declarado en AppSettings::CRONS. Añádelo al manifiesto.',
                (string) $this->getName()
            ));
        }

        // Una previsualización no toca nada ni cuenta como ejecución: no pasa
        // por el gate (para poder ver qué haría con la tarea apagada) ni se
        // registra (no debe falsear la última ejecución).
        if ($this->hasFlag($input, 'dry-run')) {
            return $this->doExecute($input, $output);
        }

        $force = $this->hasFlag($input, 'force');
        $trigger = $this->launchedByHand ? CronRun::TRIGGER_MANUAL : CronRun::TRIGGER_SCHEDULE;

        $inhibitedReason = $this->cronTasks->inhibitedReason($task['key'], $force);
        if ($inhibitedReason !== null) {
            // Se sigue escribiendo el aviso por pantalla: que un comando diga
            // siempre algo al arrancar es lo que permite datar una caída leyendo
            // var/log/cron.log, y sin eso "no corrió" y "corrió inhibida" se
            // confunden.
            (new SymfonyStyle($input, $output))->warning($inhibitedReason);

            $runId = $this->cronRunLogger->start($task['key'], $task['command'], $trigger);
            $this->cronRunLogger->finish($runId, CronRun::STATUS_DISABLED, Command::SUCCESS, $inhibitedReason);

            return Command::SUCCESS;
        }

        $runId = $this->cronRunLogger->start($task['key'], $task['command'], $trigger);
        $captor = new TeeOutput($output);

        try {
            $exitCode = $this->doExecute($input, $captor);
        } catch (\Throwable $e) {
            $this->cronRunLogger->finish(
                $runId,
                CronRun::STATUS_FAILED,
                Command::FAILURE,
                sprintf('%s: %s', $e::class, $e->getMessage()),
                $captor->getCaptured()
            );

            // La excepción sigue su curso: registrar no es tragarse el error.
            throw $e;
        }

        $status = $exitCode === Command::SUCCESS
            ? ($this->reportedStatus ?? CronRun::STATUS_DONE)
            : CronRun::STATUS_FAILED;

        $this->cronRunLogger->finish($runId, $status, $exitCode, $this->reportedDetail, $captor->getCaptured());

        return $exitCode;
    }

    /**
     * ¿Viene activada una opción booleana? Se comprueba que exista en la
     * definición para no reventar en un comando que no la declare.
     *
     * @param InputInterface $input Entrada del comando.
     * @param string         $name  Nombre de la opción.
     */
    private function hasFlag(InputInterface $input, string $name): bool
    {
        return $input->hasOption($name) && (bool) $input->getOption($name);
    }
}
