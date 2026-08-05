<?php

namespace App\Command;

use App\Repository\UsageHitRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Borra el rastro de uso (usage_hit) anterior a N días. Pensado para correr por
 * cron: por minimización (LOPD) no guardamos la telemetría para siempre.
 *
 * Por defecto retiene 90 días. --days lo ajusta; --dry-run muestra cuánto
 * borraría sin tocar nada.
 */
#[AsCommand(name: 'app:purge-usage-hits', description: 'Borra el rastro de uso anterior a N días (retención).')]
class PurgeUsageHitsCommand extends AbstractCronCommand
{
    private const DEFAULT_DAYS = 90;

    public function __construct(
        private readonly UsageHitRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Días de retención', (string) self::DEFAULT_DAYS)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No borra; solo informa');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $io->error('--days debe ser un entero positivo.');
            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(sprintf('-%d days', $days));

        if ($input->getOption('dry-run')) {
            $count = $this->repository->count([]);
            $io->note(sprintf(
                'Dry-run: se borraría el rastro anterior a %s (retención de %d días). Total actual de filas: %d.',
                $before->format('Y-m-d H:i'),
                $days,
                $count,
            ));
            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($before);
        $io->success(sprintf('Borradas %d filas de usage_hit anteriores a %s.', $deleted, $before->format('Y-m-d H:i')));

        return $deleted > 0
            ? $this->didWork(sprintf('%d filas de rastro borradas (retención de %d días)', $deleted, $days))
            : $this->nothingToDo(sprintf('Nada anterior a %s que borrar', $before->format('Y-m-d')));
    }
}
