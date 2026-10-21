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
 * Aviso SEMANAL a cada trabajador de sus días laborables recientes sin fichar y
 * sin ausencia justificada (según su horario).
 *
 * VA AL TRABAJADOR Y NO A QUIEN SUPERVISA: el trabajador es quien sabe si aquel
 * día trabajó (y añade los fichajes) o no (y pide que se registre la ausencia).
 * Antes iba por correo a un supervisor que tenía que perseguirle; quien supervisa
 * ya ve los huecos en /gestion/staff/gaps y al aprobar el mes, que no se aprueba
 * con días sin explicar.
 *
 * Deliberadamente SEMANAL, no diario: un goteo diario se acaba ignorando
 * (fatiga). El aviso en caliente (salida abierta), que sí se degrada rápido, va
 * en un comando aparte ({@see SendStaffOpenShiftAlertCommand}).
 */
#[AsCommand(name: 'app:send-staff-gaps-digest', description: 'Avisa a cada trabajador de sus días recientes sin fichar.')]
class SendStaffGapsDigestCommand extends AbstractCronCommand
{
    private const DEFAULT_DAYS = 7;

    /**
     * Clase de efecto con la que se apunta el aviso: uno por trabajador y día. La
     * ventana es "los últimos N días" y no se consume al avisar, así que dos
     * pasadas del reloj el mismo día repetirían el mismo aviso.
     */
    private const EFFECT_KIND = 'staff_gaps_digest';

    public function __construct(
        private readonly GapReport $gapReport,
        private readonly WorkerNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Cuántos días hacia atrás revisar', self::DEFAULT_DAYS)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite el aviso aunque ya conste emitido hoy')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los huecos que avisaría sin avisar');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);
        $days = max(1, (int) $input->getOption('days'));
        $from = $today->modify(sprintf('-%d days', $days));

        $rows = $this->gapReport->activeWorkersGaps($from, $today);
        if ($rows === []) {
            $io->success('Sin huecos en el período. No se avisa a nadie.');
            return $this->nothingToDo(sprintf('Sin huecos en los últimos %d días', $days));
        }

        $io->table(['Trabajador', 'Huecos', 'Días'], array_map(
            fn (array $row) => [$row['worker']->getName(), count($row['gaps']), $this->dayList($row['gaps'])],
            $rows,
        ));

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d trabajador(es) con huecos. Sin avisos.', count($rows)));
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

            $count = count($row['gaps']);
            $emitted = $this->emitOnce(
                self::EFFECT_KIND,
                fn () => $this->notifier->notify(
                    $worker,
                    Notification::KIND_WORK_GAPS,
                    1 === $count ? 'Tienes un día sin fichar' : sprintf('Tienes %d días sin fichar', $count),
                    sprintf(
                        '%s. Si trabajaste, añade los fichajes en tu calendario; si no, díselo a quien lleva el personal para que registre la ausencia.',
                        $this->dayList($row['gaps']),
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

        $io->success(sprintf('%d trabajador(es) avisado(s) de sus huecos.', $notified));

        return $this->didWork(sprintf('%d trabajadores avisados de sus huecos', $notified));
    }

    /**
     * Los días en una lista corta: «14/10, 15/10 y 16/10».
     *
     * @param list<\DateTimeImmutable> $days Los días sin fichar, en orden.
     *
     * @return string La lista para leer.
     */
    private function dayList(array $days): string
    {
        $labels = array_map(static fn (\DateTimeImmutable $d): string => $d->format('d/m'), $days);
        $last = array_pop($labels);

        return [] === $labels ? (string) $last : implode(', ', $labels) . ' y ' . $last;
    }
}
