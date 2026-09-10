<?php

namespace App\Command;

use App\Repository\CronRunRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\UsageHitRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Borra los rastros que el sistema va dejando y que nadie va a consultar ya:
 * la telemetría de uso (usage_hit), la bitácora de avisos (notification_log) y
 * el registro de ejecuciones de tareas (cron_run). Pensado para correr por cron.
 *
 * DOS RETENCIONES, no una, porque no sirven para lo mismo. La telemetría se
 * guarda 90 días por minimización (LOPD): son visitas, y no hacen falta más. Lo
 * que se envió y lo que corrió se guarda un AÑO, porque es material de
 * diagnóstico: la pregunta típica —"esta socia dice que no le llegó el aviso"—
 * puede aparecer meses después del envío, y con noventa días la respuesta sería
 * "ya no se sabe" justo cuando hace falta.
 *
 * Las dos tablas de diagnóstico caducan a la vez a propósito: el registro de
 * avisos enlaza con la ejecución que los mandó, y purgar una antes que la otra
 * dejaría filas apuntando a una ejecución borrada (la clave ajena las deja en
 * blanco, pero se pierde el rastro sin ganar nada).
 *
 * --days y --log-days ajustan cada retención; --dry-run muestra qué borraría
 * sin tocar nada.
 */
#[AsCommand(name: 'app:purge-usage-hits', description: 'Borra rastros caducados: telemetría, bitácora de avisos y ejecuciones.')]
class PurgeUsageHitsCommand extends AbstractCronCommand
{
    private const DEFAULT_DAYS = 90;

    /** Retención de lo que sirve para diagnosticar: un año. */
    private const DEFAULT_LOG_DAYS = 365;

    public function __construct(
        private readonly UsageHitRepository $repository,
        private readonly NotificationLogRepository $notificationLogs,
        private readonly CronRunRepository $cronRuns,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Días de retención del rastro de uso', (string) self::DEFAULT_DAYS)
            ->addOption('log-days', null, InputOption::VALUE_REQUIRED, 'Días de retención de la bitácora de avisos y las ejecuciones', (string) self::DEFAULT_LOG_DAYS)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No borra; solo informa');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        $logDays = (int) $input->getOption('log-days');
        if ($days < 1 || $logDays < 1) {
            $io->error('--days y --log-days deben ser enteros positivos.');
            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(sprintf('-%d days', $days));
        $logsBefore = new \DateTimeImmutable(sprintf('-%d days', $logDays));

        if ($input->getOption('dry-run')) {
            $io->note(sprintf(
                'Dry-run: se borraría el rastro de uso anterior a %s (retención %d días; %d filas en total) '
                . 'y la bitácora de avisos y ejecuciones anterior a %s (retención %d días; %d y %d filas en total).',
                $before->format('Y-m-d H:i'),
                $days,
                $this->repository->count([]),
                $logsBefore->format('Y-m-d H:i'),
                $logDays,
                $this->notificationLogs->count([]),
                $this->cronRuns->count([]),
            ));
            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($before);
        $deletedLogs = $this->notificationLogs->purgeOlderThan($logsBefore);
        $deletedRuns = $this->cronRuns->purgeOlderThan($logsBefore);

        $io->success(sprintf(
            'Borradas %d filas de rastro de uso, %d de bitácora de avisos y %d de ejecuciones.',
            $deleted,
            $deletedLogs,
            $deletedRuns,
        ));

        $total = $deleted + $deletedLogs + $deletedRuns;

        return $total > 0
            ? $this->didWork(sprintf(
                '%d de rastro (retención %d días), %d de avisos y %d de ejecuciones (retención %d días)',
                $deleted,
                $days,
                $deletedLogs,
                $deletedRuns,
                $logDays,
            ))
            : $this->nothingToDo('Nada caducado que borrar');
    }
}
