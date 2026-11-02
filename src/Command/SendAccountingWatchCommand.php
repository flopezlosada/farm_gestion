<?php

namespace App\Command;

use App\Service\Accounting\Agenda\AccountingAgenda;
use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Watch\AccountingWatchAlert;
use App\Service\Accounting\Watch\AccountingWatchPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La vigilancia de la contabilidad: cada mañana repasa la portada («Por hacer») y
 * avisa a quien la lleva, para que lo pendiente no dependa de que alguien entre a
 * mirarla.
 *
 * Lo urgente avisa el día que aparece y se repite cada pocos días mientras siga; el
 * resto va en un resumen semanal ({@see AccountingWatchPlan}). La portada sigue
 * siendo la única fuente: esta tarea no decide qué está mal, sólo lo empuja.
 */
#[AsCommand(name: 'app:send-accounting-watch', description: 'Avisa de lo pendiente en la portada de contabilidad.')]
class SendAccountingWatchCommand extends AbstractCronCommand
{
    /** Por qué no se avisa cuando nadie tiene el permiso. */
    public const NO_RESPONSIBLE = 'Nadie lleva la contabilidad (nadie tiene el permiso de escribir en ella; a un admin hay que dárselo aparte): no se avisa a nadie.';

    public function __construct(
        private readonly AccountingAgenda $agenda,
        private readonly AccountingWatchPlan $plan,
        private readonly AccountingWatchAlert $alert,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Hacer como si hoy fuera esta fecha (YYYY-MM-DD)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite los avisos aunque ya consten emitidos')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista lo que avisaría sin avisar');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $date = $this->optionalDate($input, $io);
        if (false === $date) {
            return Command::INVALID;
        }
        $today = $date ?? new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));

        $items = $this->agenda->items($today);
        if ([] === $items) {
            $io->success('La contabilidad está al día. No se avisa a nadie.');

            return $this->nothingToDo('Contabilidad al día');
        }

        $urgent = $this->plan->urgent($items);
        $weekly = $this->plan->weekly($items);
        $io->table(['Urgencia', 'Tarea'], array_map(
            static fn (AgendaItem $item): array => [$item->urgency->name, $item->title],
            $items,
        ));

        // Sin nadie que lleve la contabilidad no se avisa ni se apunta nada como avisado:
        // la ejecución sale en rojo y, el día que alguien tenga el permiso, el
        // siguiente intento le manda todo lo pendiente.
        $orphan = !$this->alert->hasResponsibles();
        if ($orphan) {
            $io->warning(self::NO_RESPONSIBLE);
        }

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d urgente(s) y %d para el resumen semanal. Sin avisos.', \count($urgent), \count($weekly)));

            return Command::SUCCESS;
        }
        if ($orphan) {
            return $this->failed(self::NO_RESPONSIBLE);
        }

        $window = $this->plan->urgentWindow($today);
        $sentUrgent = \count(array_filter(array_map(
            fn (AgendaItem $item): bool => $this->emitOnce(
                AccountingWatchAlert::KIND_URGENT,
                fn () => $this->alert->urgent($item),
                $input,
                $item->key(),
                $window,
            ),
            $urgent,
        )));

        $sentWeekly = [] !== $weekly && $this->emitOnce(
            AccountingWatchAlert::KIND_WEEKLY,
            fn () => $this->alert->weekly($weekly),
            $input,
            self::EFFECT_ONCE_PER_RUN,
            $this->plan->week($today),
        );

        if (0 === $sentUrgent && !$sentWeekly) {
            return $this->nothingToDo('Nada nuevo que avisar (ya avisado)');
        }

        $summary = sprintf('%d aviso(s) urgente(s)%s', $sentUrgent, $sentWeekly ? ' y el resumen semanal' : '');
        $io->success($summary);

        return $this->didWork($summary);
    }
}
