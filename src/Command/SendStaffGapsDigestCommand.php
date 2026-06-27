<?php

namespace App\Command;

use App\Service\AppSettings;
use App\Service\Staff\GapReport;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Digest SEMANAL al supervisor con los huecos del registro de jornada: por cada
 * trabajador activo, los días laborables recientes sin fichar y sin ausencia
 * justificada. Es el mismo panel de huecos de la web, pero empujado al correo
 * para que el supervisor lo persiga sin tener que entrar.
 *
 * Deliberadamente SEMANAL, no diario: un goteo diario se acaba ignorando
 * (fatiga). El aviso en caliente (salida abierta), que sí se degrada rápido, va
 * en un comando aparte ({@see SendStaffOpenShiftAlertCommand}).
 *
 * Si no hay ningún hueco en el período, no envía nada (no se manda correo vacío).
 * Pensado para cron semanal. El interruptor general de email ({@see \App\Mailer\KillSwitchMailer})
 * sigue mandando por encima de todo.
 */
#[AsCommand(name: 'app:send-staff-gaps-digest', description: 'Digest semanal al supervisor con los huecos del registro de jornada.')]
class SendStaffGapsDigestCommand extends Command
{
    private const DEFAULT_DAYS = 7;

    public function __construct(
        private readonly GapReport $gapReport,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Email del supervisor (obligatorio si no es dry-run)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Cuántos días hacia atrás revisar', self::DEFAULT_DAYS)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los huecos que enviaría sin enviar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $to = $input->getOption('to');

        // Gate de la tarea programada: apagada en /gestion/settings, ni se ejecuta.
        if (!$dryRun && !$input->getOption('force') && !$this->settings->getBool(AppSettings::CRON_STAFF_GAPS_DIGEST)) {
            $io->warning('La tarea del digest de huecos está desactivada en /gestion/settings. No se ejecuta.');
            return Command::SUCCESS;
        }

        // Toggle de email: apagado, el cron corre pero no envía (verde para el cron).
        if (!$dryRun && !$this->settings->getBool(AppSettings::EMAIL_STAFF_GAPS)) {
            $io->warning('El aviso de huecos está desactivado en /gestion/settings. No se envía nada.');
            return Command::SUCCESS;
        }

        if (!$dryRun && !$to) {
            $io->error('Falta --to=email del supervisor (o usa --dry-run).');
            return Command::FAILURE;
        }

        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);
        $days = max(1, (int) $input->getOption('days'));
        $from = $today->modify(sprintf('-%d days', $days));

        $rows = $this->gapReport->activeWorkersGaps($from, $today);

        if ($rows === []) {
            $io->success('Sin huecos en el período. No se envía nada.');
            return Command::SUCCESS;
        }

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['worker']->getName(),
                count($row['gaps']),
                implode(', ', array_map(static fn (\DateTimeImmutable $d) => $d->format('d/m'), $row['gaps'])),
            ];
        }
        $io->table(['Trabajador', 'Huecos', 'Días'], $tableRows);

        if ($dryRun) {
            $io->success(sprintf('Dry-run: %d trabajador(es) con huecos. Sin envío.', count($rows)));
            return Command::SUCCESS;
        }

        $message = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('CSA Vega · Huecos del registro de jornada (últimos %d días)', $days))
            ->htmlTemplate('email/staff_gaps_digest.html.twig')
            ->textTemplate('email/staff_gaps_digest.txt.twig')
            ->context([
                'from' => $from,
                'today' => $today,
                'days' => $days,
                'rows' => $rows,
            ]);

        $this->mailer->send($message);
        $io->success(sprintf('Enviado a %s · %d trabajador(es) con huecos.', $to, count($rows)));

        return Command::SUCCESS;
    }
}
