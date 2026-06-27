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
 * Aviso EN CALIENTE al supervisor cuando un trabajador se deja una entrada sin
 * cerrar de un día anterior (salida abierta): el olvido de fichar la salida, que
 * se degrada rápido (cuanto más tarde se corrige, peor se recuerda la hora real).
 *
 * Es la ÚNICA excepción al "no goteo diario": corre a diario pero solo envía
 * cuando hay alguna salida abierta, así que no genera ruido en condiciones
 * normales. Los huecos sin más van en el digest semanal
 * ({@see SendStaffGapsDigestCommand}).
 *
 * El tramo abierto del propio día en curso NO cuenta (la persona puede seguir
 * trabajando); solo los de días anteriores.
 */
#[AsCommand(name: 'app:send-staff-open-shift-alert', description: 'Avisa al supervisor de salidas abiertas (entradas sin cerrar de días anteriores).')]
class SendStaffOpenShiftAlertCommand extends Command
{
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista las salidas abiertas que avisaría sin enviar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $to = $input->getOption('to');

        if (!$dryRun && !$input->getOption('force') && !$this->settings->getBool(AppSettings::CRON_STAFF_OPEN_SHIFT_ALERT)) {
            $io->warning('La tarea del aviso de salida abierta está desactivada en /gestion/settings. No se ejecuta.');
            return Command::SUCCESS;
        }

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

        $rows = $this->gapReport->staleOpenShifts($today);

        if ($rows === []) {
            $io->success('Ninguna salida abierta. No se envía nada.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Trabajador', 'Entrada sin cerrar'],
            array_map(static fn (array $r) => [$r['worker']->getName(), $r['since']->format('d/m/Y H:i')], $rows),
        );

        if ($dryRun) {
            $io->success(sprintf('Dry-run: %d salida(s) abierta(s). Sin envío.', count($rows)));
            return Command::SUCCESS;
        }

        $message = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('CSA Vega · %d salida(s) abierta(s) en el registro de jornada', count($rows)))
            ->htmlTemplate('email/staff_open_shift_alert.html.twig')
            ->textTemplate('email/staff_open_shift_alert.txt.twig')
            ->context([
                'today' => $today,
                'rows' => $rows,
            ]);

        $this->mailer->send($message);
        $io->success(sprintf('Enviado a %s · %d salida(s) abierta(s).', $to, count($rows)));

        return Command::SUCCESS;
    }
}
