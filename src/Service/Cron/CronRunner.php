<?php

namespace App\Service\Cron;

use App\Command\AbstractCronCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Lanza una tarea programada a mano, en proceso.
 *
 * Se ejecuta con la API de consola de Symfony en lugar de `exec`/`proc_open`
 * porque el hosting compartido puede tenerlos deshabilitados. Es el puente
 * manual mientras el reloj esté caído — hoy, congelar el listado los lunes y
 * lanzar el recordatorio los miércoles se hace desde aquí — y sigue siendo útil
 * después.
 *
 * Esta clase existe para juntar en un sitio lo que estaba copiado en
 * {@see \App\Controller\SettingsController} y
 * {@see \App\Controller\SettingsDiagnosticsController}, cada uno con su propia
 * lista blanca (la del segundo, con sólo dos tareas). La lista blanca ahora es
 * el manifiesto {@see \App\Service\AppSettings::CRONS}, sin copias.
 *
 * Lo que NO unifica es el modo: las dos pantallas lanzan con intenciones
 * distintas y así seguía siendo antes de existir este servicio. Configuración
 * ejecuta forzando (es el sustituto del reloj caído); diagnóstico ejecuta como
 * lo haría el reloj, porque su razón de ser es comprobar qué haría el cron. De
 * ahí {@see CronRunMode}.
 *
 * NO evalúa los interruptores: eso lo hace {@see AbstractCronCommand}, que cubre
 * también las ejecuciones por consola del cron — las únicas que hay en
 * producción.
 */
class CronRunner
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly CronTaskRegistry $tasks,
    ) {
    }

    /**
     * Ejecuta una tarea del manifiesto y devuelve su resultado.
     *
     * @param string      $taskKey    Clave declarada en {@see \App\Service\AppSettings::CRONS}.
     * @param CronRunMode $mode       Previsualizar, ejecutar como el reloj o forzar.
     * @param string|null $adminEmail Email de quien lanza, para las tareas que exigen destinatario.
     * @throws \InvalidArgumentException Si la clave no está en el manifiesto.
     */
    public function run(string $taskKey, CronRunMode $mode, ?string $adminEmail = null): CronRunResult
    {
        $task = $this->tasks->get($taskKey)
            ?? throw new \InvalidArgumentException(sprintf('Tarea desconocida "%s".', $taskKey));

        $label = $this->tasks->label($taskKey);
        $args = ['command' => $task['command']];

        if ($mode === CronRunMode::Preview) {
            $args['--dry-run'] = true;
        } elseif ($mode === CronRunMode::Forced) {
            $args['--force'] = true;
        }

        // Las tareas que envían a una persona concreta (supervisión,
        // administración) necesitan destinatario en ejecución real: en el cron lo
        // fija su propia configuración, y aquí se dirige a quien pulsa el botón,
        // para no mandar correo a terceros desde una prueba.
        if (!$mode->isPreview() && ($task['needs_recipient'] ?? false)) {
            if ($adminEmail === null || trim($adminEmail) === '') {
                return new CronRunResult(
                    $taskKey,
                    $task['command'],
                    $label,
                    $mode,
                    null,
                    '',
                    'Esta tarea necesita un destinatario y tu usuario no tiene email configurado. Usa la previsualización o configura tu email.'
                );
            }
            $args['--to'] = trim($adminEmail);
        }

        // Volúmenes pequeños, pero el envío por SMTP puede tardar: que no lo
        // corte PHP a mitad.
        @set_time_limit(0);

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        // Que la ejecución quede registrada como lanzada POR UNA PERSONA. Es un
        // eje distinto de --force (que sólo dice si se salta el interruptor):
        // diagnóstico lanza sin forzar y sigue siendo manual, y si se dedujera
        // de --force la pantalla daría por vivo un reloj parado.
        //
        // OJO: los comandos con descripción en #[AsCommand] se registran
        // envueltos en LazyCommand (Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass),
        // así que hay que desenvolverlos o el instanceof falla en silencio y
        // toda ejecución manual se registraría como si la hubiera hecho el reloj.
        $command = $application->find($task['command']);
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        if (!$command instanceof AbstractCronCommand) {
            // El manifiesto sólo declara tareas que heredan de la base (lo vigila
            // CronManifestTest); si no, es un bug y vale más que se note.
            throw new \LogicException(sprintf(
                'El comando "%s" de la tarea "%s" no hereda de AbstractCronCommand.',
                $task['command'],
                $taskKey
            ));
        }
        $command->markLaunchedByHand();

        try {
            $exitCode = $application->run(new ArrayInput($args), $output);
            $text = $output->fetch();
        } catch (\Throwable $e) {
            $exitCode = 1;
            $text = sprintf("El comando lanzó una excepción:\n\n%s: %s", $e::class, $e->getMessage());
        }

        return new CronRunResult($taskKey, $task['command'], $label, $mode, $exitCode, trim($text));
    }
}
