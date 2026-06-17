<?php

namespace App\Command;

use App\Repository\StayRepository;
use App\Service\AppSettings;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Recordatorio INTERNO al equipo con las llegadas y salidas confirmadas del
 * albergue en los próximos días (preparar camas, etc.). No escribe a los
 * voluntarios: es un digest al buzón de gestión que se pasa en --to.
 *
 * Pensado para cron diario. Si no hay ni llegadas ni salidas en el período, no
 * envía nada (el equipo no recibe correo vacío). Mismos gates que el resto de
 * comandos de email: la tarea programada ({@see AppSettings::CRON_ALBERGUE_REMINDER})
 * y el envío ({@see AppSettings::EMAIL_ALBERGUE_REMINDER}) se gobiernan desde
 * /gestion/settings; el kill-switch general corta por encima de todo.
 */
#[AsCommand(name: 'app:send-albergue-arrivals-reminder', description: 'Recordatorio al equipo con las llegadas y salidas próximas del albergue.')]
class SendAlbergueArrivalsReminderCommand extends Command
{
    private const DEFAULT_DAYS = 7;

    public function __construct(
        private readonly StayRepository $stayRepository,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Email del equipo (obligatorio si no es dry-run)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Horizonte en días hacia adelante', self::DEFAULT_DAYS)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista lo que enviaría sin enviar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $to = $input->getOption('to');

        // Gate de la tarea programada: apagada en /gestion/settings, la tarea no
        // hace nada (verde para no disparar alertas del cron). dry-run y --force
        // (ejecución manual explícita) la saltan.
        if (!$dryRun && !$input->getOption('force') && !$this->settings->getBool(AppSettings::CRON_ALBERGUE_REMINDER)) {
            $io->warning('La tarea programada del recordatorio del albergue está desactivada en /gestion/settings. No se ejecuta.');
            return Command::SUCCESS;
        }

        // Toggle de envío: apagado, el cron no manda nada (verde). dry-run sigue.
        if (!$dryRun && !$this->settings->getBool(AppSettings::EMAIL_ALBERGUE_REMINDER)) {
            $io->warning('El recordatorio del albergue está desactivado en /gestion/settings. No se envía nada.');
            return Command::SUCCESS;
        }

        if (!$dryRun && !$to) {
            $io->error('Falta --to=email del equipo (o usa --dry-run).');
            return Command::FAILURE;
        }

        $days = max(1, (int) $input->getOption('days'));
        $from = new \DateTimeImmutable('today');
        $until = $from->modify(sprintf('+%d days', $days));

        $arrivals = $this->stayRepository->findArrivalsBetween($from, $until);
        $departures = $this->stayRepository->findDeparturesBetween($from, $until);

        $io->section(sprintf('Próximos %d días (%s → %s)', $days, $from->format('d/m'), $until->modify('-1 day')->format('d/m')));
        $io->text(sprintf('Llegadas: %d · Salidas: %d', count($arrivals), count($departures)));

        if ($arrivals === [] && $departures === []) {
            $io->note('Sin llegadas ni salidas en el período. No se envía nada.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($arrivals as $stay) {
                $io->writeln(sprintf('  + llega %s · %s', $stay->getArrivalDate()->format('d/m'), $stay->getHelper()->getName()));
            }
            foreach ($departures as $stay) {
                $io->writeln(sprintf('  - sale  %s · %s', $stay->getDepartureDate()->format('d/m'), $stay->getHelper()->getName()));
            }
            $io->success('Dry-run: sin envío.');
            return Command::SUCCESS;
        }

        $message = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('CSA Vega · Albergue: %d llegada(s) y %d salida(s) próximas', count($arrivals), count($departures)))
            ->htmlTemplate('email/albergue_reminder.html.twig')
            ->textTemplate('email/albergue_reminder.txt.twig')
            ->context([
                'from' => $from,
                'until' => $until,
                'days' => $days,
                'arrivals' => $arrivals,
                'departures' => $departures,
            ]);

        $this->mailer->send($message);
        $io->success(sprintf('Enviado a %s · %d llegada(s), %d salida(s).', $to, count($arrivals), count($departures)));

        return Command::SUCCESS;
    }
}
