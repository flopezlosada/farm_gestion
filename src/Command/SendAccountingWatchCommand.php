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

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d urgente(s) y %d para el resumen semanal. Sin avisos.', \count($urgent), \count($weekly)));

            return Command::SUCCESS;
        }

        $sentUrgent = \count(array_filter(array_map(
            fn (AgendaItem $item): bool => $this->emitOnce(
                AccountingWatchAlert::KIND_URGENT,
                fn () => $this->alert->urgent($item),
                $input,
                $item->key(),
                $today,
            ),
            $this->dueUrgent($urgent, $today, (bool) $input->getOption('resend')),
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

    /**
     * Las tareas urgentes a las que hoy les toca aviso: las que nunca se avisaron y las
     * que llevan {@see AccountingWatchPlan::URGENT_EVERY_DAYS} días desde el último,
     * contando también el aviso que dio otro camino ({@see AgendaItem::$alertedOn}).
     * Con --resend, todas. El último aviso de todas sale en una sola consulta.
     *
     * @param list<AgendaItem>   $urgent Las tareas urgentes de hoy.
     * @param \DateTimeImmutable $today  Hoy.
     * @param bool               $resend Si se pide repetirlos todos.
     *
     * @return list<AgendaItem>
     */
    private function dueUrgent(array $urgent, \DateTimeImmutable $today, bool $resend): array
    {
        if ($resend) {
            return $urgent;
        }
        $last = $this->effectLedger->lastOccurrences(
            AccountingWatchAlert::KIND_URGENT,
            array_map(static fn (AgendaItem $item): string => $item->key(), $urgent),
            $today->getTimezone(),
        );

        // max() con null delante o detrás devuelve la fecha (null es menor que cualquier
        // objeto); con las dos, la más reciente.
        return array_values(array_filter($urgent, fn (AgendaItem $item): bool => $this->plan->isUrgentDue(
            max($last[$item->key()] ?? null, $item->alertedOn),
            $today,
        )));
    }
}
