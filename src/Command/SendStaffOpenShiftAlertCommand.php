<?php

namespace App\Command;

use App\Entity\Notification;
use App\Service\Staff\GapReport;
use App\Service\Staff\WorkerNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aviso EN CALIENTE al trabajador que se dejó una entrada sin cerrar de un día
 * anterior (salida abierta): el olvido de fichar la salida, que se degrada rápido
 * (cuanto más tarde se corrige, peor se recuerda la hora real).
 *
 * VA AL TRABAJADOR Y NO A QUIEN SUPERVISA: es quien sabe a qué hora salió y quien
 * puede añadirla. Antes iba por correo a un supervisor que tenía que perseguirle
 * para preguntárselo; quien supervisa ya lo ve al aprobar el mes, que no se
 * puede aprobar con un tramo abierto.
 *
 * Es la ÚNICA excepción al "no goteo diario": corre a diario pero solo avisa a
 * quien tiene alguna salida abierta. El tramo abierto del propio día en curso NO
 * cuenta (la persona puede seguir trabajando); solo los de días anteriores.
 */
#[AsCommand(name: 'app:send-staff-open-shift-alert', description: 'Avisa a cada trabajador de su salida sin fichar de un día anterior.')]
class SendStaffOpenShiftAlertCommand extends AbstractCronCommand
{
    /**
     * Clase de efecto con la que se apunta el aviso: uno por trabajador y día.
     * Una salida abierta sigue abierta hasta que alguien la cierra, así que sin
     * esto cada pasada del reloj repetiría el mismo aviso.
     */
    private const EFFECT_KIND = 'staff_open_shift_alert';

    public function __construct(
        private readonly GapReport $gapReport,
        private readonly WorkerNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite el aviso aunque ya conste emitido hoy')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista las salidas abiertas que avisaría sin avisar');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);

        $rows = $this->gapReport->staleOpenShifts($today);
        if ($rows === []) {
            $io->success('Ninguna salida abierta. No se avisa a nadie.');
            return $this->nothingToDo('Ninguna salida abierta');
        }

        $io->table(
            ['Trabajador', 'Entrada sin cerrar'],
            array_map(static fn (array $r) => [$r['worker']->getName(), $r['since']->format('d/m/Y H:i')], $rows),
        );

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d salida(s) abierta(s). Sin avisos.', count($rows)));
            return Command::SUCCESS;
        }

        $notified = 0;
        $withoutAccount = [];
        foreach ($rows as $row) {
            $worker = $row['worker'];
            if (null === $this->notifier->accountOf($worker)) {
                $withoutAccount[] = $worker->getName();
                continue;
            }

            $since = $row['since']->setTimezone($madrid);
            $emitted = $this->emitOnce(
                self::EFFECT_KIND,
                fn () => $this->notifier->notify(
                    $worker,
                    Notification::KIND_WORK_OPEN_SHIFT,
                    'Te quedó la jornada abierta',
                    sprintf(
                        'Fichaste la entrada el %s a las %s y no la salida. Añádela en ese día de tu calendario mientras te acuerdas de la hora.',
                        $since->format('d/m'),
                        $since->format('H:i'),
                    ),
                    self::EFFECT_KIND,
                ),
                $input,
                'worker-' . $worker->getId(),
                $today,
            );
            if ($emitted) {
                ++$notified;
            }
        }

        if ($withoutAccount !== []) {
            $io->warning('Sin cuenta de acceso, no se les puede avisar: ' . implode(', ', $withoutAccount));
        }
        if ($notified === 0) {
            return $this->nothingToDo('Nadie a quien avisar hoy (ya avisados o sin cuenta)');
        }

        $io->success(sprintf('%d trabajador(es) avisado(s) de su salida abierta.', $notified));

        return $this->didWork(sprintf('%d trabajadores avisados de su salida abierta', $notified));
    }
}
