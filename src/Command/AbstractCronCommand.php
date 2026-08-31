<?php

namespace App\Command;

use App\Entity\CronRun;
use App\Service\Cron\CronRunLogger;
use App\Service\Cron\CronTaskRegistry;
use App\Service\Cron\EffectLedger;
use App\Service\Cron\TaskLock;
use App\Service\Cron\TeeOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base de las tareas programadas: aplica el gate de interruptores, impide que
 * la tarea se solape consigo misma y registra la ejecución. Las clases hijas
 * implementan sólo su trabajo, en {@see self::doExecute()}.
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
 * lo declara {@see self::markTriggeredBy()}, que llama {@see \App\Service\Cron\CronRunner}.
 * Si se dedujera de `--force`, una tarea lanzada desde la pantalla de
 * diagnóstico (que ejecuta sin forzar, como lo haría el reloj) se registraría
 * como si la hubiera disparado el reloj, y la pantalla daría por vivo un
 * planificador parado.
 */
abstract class AbstractCronCommand extends Command
{
    /**
     * Referencia por defecto de {@see self::emitOnce()}: el efecto es único por
     * ejecución y no por destinatario (un resumen a administración, un informe),
     * así que no hay nada que referenciar más allá de la fecha.
     */
    protected const EFFECT_ONCE_PER_RUN = 'global';

    protected CronTaskRegistry $cronTasks;

    protected CronRunLogger $cronRunLogger;

    protected TaskLock $taskLock;

    protected EffectLedger $effectLedger;

    /** Estado reportado por la hija ({@see self::nothingToDo()} / {@see self::didWork()}). */
    private ?string $reportedStatus = null;

    /** Resumen de una línea reportado por la hija. */
    private ?string $reportedDetail = null;

    /**
     * Quién ha pedido esta ejecución, uno de los CronRun::TRIGGER_*.
     *
     * Guarda el origen y no un booleano "¿a mano?": con tres orígenes posibles
     * (crontab del hosting, tick horario y persona) un booleano obliga a
     * traducirlo en el punto de uso y no sabe expresar el tercero.
     *
     * El valor por defecto es el crontab porque ese camino —`bin/console`
     * ejecutado por el hosting— no pasa por ninguna pieza que pueda declararse.
     */
    private string $triggerSource = CronRun::TRIGGER_SCHEDULE;

    /**
     * Declara quién pide la ejecución. Lo llama {@see \App\Service\Cron\CronRunner},
     * que es por donde entran tanto los botones de la pantalla como el tick.
     *
     * @param string $trigger Uno de los CronRun::TRIGGER_*.
     */
    public function markTriggeredBy(string $trigger): void
    {
        $this->triggerSource = $trigger;
    }

    /**
     * Dependencias del andamiaje (gate y registro), inyectadas por setter para
     * que las hijas no tengan que arrastrarlas en su constructor: no son
     * dependencias de su dominio, y así añadir una tarea nueva no obliga a
     * recordar nada.
     *
     * @param CronTaskRegistry $cronTasks     Lectura del manifiesto de tareas.
     * @param CronRunLogger    $cronRunLogger Escritura del registro de ejecuciones.
     * @param TaskLock         $taskLock      Cerrojo de no solapamiento por tarea.
     * @param EffectLedger     $effectLedger  Guardián de idempotencia de efectos.
     */
    #[Required]
    public function setCronScaffolding(
        CronTaskRegistry $cronTasks,
        CronRunLogger $cronRunLogger,
        TaskLock $taskLock,
        EffectLedger $effectLedger,
    ): void {
        $this->cronTasks = $cronTasks;
        $this->cronRunLogger = $cronRunLogger;
        $this->taskLock = $taskLock;
        $this->effectLedger = $effectLedger;
    }

    /**
     * Produce un efecto externo una sola vez, aunque la tarea se repita.
     *
     * Atajo sobre {@see EffectLedger} para el caso más común en una tarea
     * programada: el efecto es único por ejecución y por día. Cuando la unidad
     * es más fina (un aviso por persona), se pasa su referencia.
     *
     * El cerrojo de la tarea y esto son cosas distintas y las dos hacen falta:
     * el cerrojo evita que dos procesos trabajen a la vez, y esto evita que un
     * reintento posterior repita lo que ya salió.
     *
     * @param string                  $kind      Clase de efecto ("admin_delivery_summary"…).
     * @param callable                $effect    Lo que hay que hacer una sola vez.
     * @param InputInterface|null     $input     Entrada, para leer --resend si la declara.
     * @param string                  $reference A qué o quién se refiere.
     * @param \DateTimeInterface|null $on        Fecha de negocio (por defecto, hoy).
     * @param string|null             $target    Destino, para poder auditarlo.
     * @return bool true si el efecto se ha producido ahora; false si ya constaba.
     */
    protected function emitOnce(
        string $kind,
        callable $effect,
        ?InputInterface $input = null,
        string $reference = self::EFFECT_ONCE_PER_RUN,
        ?\DateTimeInterface $on = null,
        ?string $target = null,
    ): bool {
        return $this->effectLedger->once(
            $kind,
            $reference,
            $on ?? new \DateTimeImmutable('today'),
            $effect,
            $target,
            $input !== null && $this->hasFlag($input, 'resend'),
        );
    }

    /**
     * Lee una opción de fecha del tipo `--date=YYYY-MM-DD`, que varias tareas
     * ofrecen para apuntar a un día concreto en pruebas y reenvíos.
     *
     * Parseo ESTRICTO y no `new \DateTimeImmutable($valor)`: éste acepta
     * "2026-02-30" y hace rollover silencioso a marzo, así que la tarea trabajaría
     * sobre el día equivocado sin decir nada.
     *
     * @param InputInterface $input  Entrada del comando.
     * @param SymfonyStyle   $io     Salida, para reportar la fecha inválida.
     * @param string         $option Nombre de la opción.
     * @return \DateTimeImmutable|null|false La fecha, null si no se pasó, false si es inválida.
     */
    protected function optionalDate(InputInterface $input, SymfonyStyle $io, string $option = 'date'): \DateTimeImmutable|null|false
    {
        $date = $input->getOption($option);
        if ($date === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $io->error(sprintf('Fecha inválida en --%s: "%s". Formato esperado YYYY-MM-DD.', $option, $date));

            return false;
        }

        return $parsed;
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
            $this->triggerSource = CronRun::TRIGGER_SCHEDULE;
        }
    }

    /**
     * Resuelve la tarea en el manifiesto, aparta las previsualizaciones y toma
     * el cerrojo de no solapamiento antes de dejar trabajar a nadie.
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
        // por el gate (para poder ver qué haría con la tarea apagada), no se
        // registra (no debe falsear la última ejecución) y no necesita cerrojo
        // (no hay efecto que duplicar).
        if ($this->hasFlag($input, 'dry-run')) {
            return $this->doExecute($input, $output);
        }

        // Cerrojo ANTES del gate y del registro, para que toda la ejecución real
        // quede serializada: si un tick reintenta una tarea que la pasada
        // anterior dejó viva, el segundo proceso se retira aquí en vez de
        // duplicar su trabajo.
        if (!$this->taskLock->acquire($task['key'])) {
            (new SymfonyStyle($input, $output))->warning(sprintf(
                'La tarea «%s» ya está ejecutándose en otro proceso. Esta pasada se retira.',
                $this->cronTasks->label($task['key'])
            ));

            // Éxito, no fallo: el sistema está haciendo justo lo que debe. Un
            // código de error haría que el reloj externo avisara de una avería
            // inexistente. Y no se registra ejecución, porque no ha habido
            // ninguna: la que sí está corriendo (o la que murió sin cerrar su
            // fila) es la que la pantalla debe seguir mostrando.
            return Command::SUCCESS;
        }

        try {
            return $this->runLockedTask($task, $input, $output);
        } finally {
            $this->taskLock->release($task['key']);
        }
    }

    /**
     * El cuerpo de la ejecución una vez tomado el cerrojo: gate, registro de
     * arranque, trabajo de la hija y cierre del registro.
     *
     * @param array<string, mixed> $task   Metadatos de la tarea en el manifiesto.
     * @param InputInterface       $input  Entrada del comando.
     * @param OutputInterface      $output Salida real.
     */
    private function runLockedTask(array $task, InputInterface $input, OutputInterface $output): int
    {
        $force = $this->hasFlag($input, 'force');
        $trigger = $this->triggerSource;

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
