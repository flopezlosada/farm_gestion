<?php

namespace App\Command;

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
class SendStaffOpenShiftAlertCommand extends AbstractCronCommand
{
    /**
     * Clase de efecto con la que se apunta el envío: uno por día. Una salida
     * abierta sigue abierta hasta que alguien la cierra, así que sin esto cada
     * pasada del reloj repetiría el mismo aviso.
     */
    private const EFFECT_KIND = 'staff_open_shift_alert';

    public function __construct(
        private readonly GapReport $gapReport,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Email del supervisor (obligatorio si no es dry-run)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite el envío aunque ya conste emitido hoy (para un correo que no llegó)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista las salidas abiertas que avisaría sin enviar nada');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $to = $input->getOption('to');

        if (!$dryRun && !$to) {
            $io->error('Falta --to=email del supervisor (o usa --dry-run).');
            return Command::FAILURE;
        }

        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);

        $rows = $this->gapReport->staleOpenShifts($today);

        if ($rows === []) {
            $io->success('Ninguna salida abierta. No se envía nada.');
            return $this->nothingToDo('Ninguna salida abierta');
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

        $emitted = $this->emitOnce(
            self::EFFECT_KIND,
            fn () => $this->mailer->send($message),
            $input,
            on: $today,
            target: (string) $to,
        );

        if (!$emitted) {
            $io->note('El aviso de hoy ya se había enviado. No se repite.');
            return $this->nothingToDo('El aviso de hoy ya se había enviado');
        }

        $io->success(sprintf('Enviado a %s · %d salida(s) abierta(s).', $to, count($rows)));

        return $this->didWork(sprintf('%d salidas abiertas avisadas a %s', count($rows), $to));
    }
}
