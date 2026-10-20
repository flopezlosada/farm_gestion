<?php

namespace App\Command;

use App\Service\Notification\StaffAudience;
use App\Service\Staff\WorkMonth;
use App\Service\Staff\WorkMonthClosing;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Avisa a gestión laboral de que el registro de jornada del mes anterior está
 * sin aprobar.
 *
 * Va a quien tenga ROLE_GESTION_LABORAL_EDIT, que es quien puede aprobar: la
 * responsabilidad es de la asociación, no de cada trabajador.
 *
 * POR ESCALONES, NO A DIARIO: el día 1, y otra vez el 5 y el 10 si sigue
 * quedando alguien. Un correo diario se aprende a ignorar en una semana; tres
 * al mes con la lista de quién falta se leen. Pasado el 10 no insiste más: la
 * pantalla de cierre sigue diciéndolo.
 *
 * La idempotencia se ancla en el MES y el escalón, no en hoy: la tarea corre a
 * diario y manda el escalón más alto alcanzado si aún no salió. Así, si el reloj
 * se cae el día 1, el aviso sale el 2 en vez de perderse.
 */
#[AsCommand(name: 'app:send-staff-month-close-reminder', description: 'Avisa a gestión laboral de los meses del registro de jornada sin aprobar.')]
class SendStaffMonthCloseReminderCommand extends AbstractCronCommand
{
    /** Clase de efecto en el guardián de idempotencia y en la bitácora de avisos. */
    private const KIND = 'staff_month_close_reminder';

    /** El permiso que hace a alguien destinatario: el que deja aprobar. */
    private const APPROVER_ROLE = 'ROLE_GESTION_LABORAL_EDIT';

    /** Días del mes en que se avisa, si sigue habiendo algo pendiente. */
    private const STEPS = [1, 5, 10];

    public function __construct(
        private readonly WorkMonthClosing $closing,
        private readonly StaffAudience $staff,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Día de referencia (YYYY-MM-DD), para probar escalones sin esperar')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Reenvía aunque el aviso de este escalón ya conste emitido')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, sólo lista qué avisaría');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $date = $this->optionalDate($input, $io);
        if ($date === false) {
            return Command::FAILURE;
        }
        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = $date !== null
            ? new \DateTimeImmutable($date->format('Y-m-d'), $madrid)
            : new \DateTimeImmutable('today', $madrid);

        $step = self::stepFor((int) $today->format('j'));
        $month = WorkMonth::containing($today)->previous();

        $pending = $this->closing->pendingFor($month, $today);
        if ($pending === []) {
            $io->note(sprintf('Todo %s está aprobado.', $month->label()));

            return $this->nothingToDo(sprintf('%s aprobado entero', $month->label()));
        }

        $io->section(sprintf('%s · %d trabajador(es) sin aprobar (escalón del día %d)', $month->label(), \count($pending), $step));
        foreach ($pending as $row) {
            $io->writeln(sprintf(
                '· %s%s%s',
                $row['worker']->getName(),
                $row['issues'] > 0 ? sprintf(' — %d día(s) a corregir', $row['issues']) : '',
                $row['worker']->getWorkdayType() === null ? ' — sin tipo de jornada' : '',
            ));
        }

        $to = $this->staff->emailsWithRole(self::APPROVER_ROLE);
        if ($to === []) {
            // No se apunta como emitido: el día que alguien con el permiso tenga
            // correo, este aviso tiene que salir.
            $io->warning('Nadie con permiso de escritura de personal tiene correo: no hay a quién avisar.');

            return $this->nothingToDo('⚠ sin destinatario con permiso de personal');
        }

        if ($input->getOption('dry-run')) {
            $io->writeln('Se avisaría a: ' . implode(', ', $to));

            return Command::SUCCESS;
        }

        $emitted = $this->emitOnce(
            self::KIND,
            fn () => $this->mailer->send($this->buildMessage($month, $pending, $to)),
            $input,
            reference: sprintf('%s:step-%d', $month->key(), $step),
            on: $month->start(),
            target: implode(', ', $to),
        );

        if (!$emitted) {
            $io->note('El aviso de este escalón ya se había enviado. No se repite.');

            return $this->nothingToDo(sprintf('%s: aviso del día %d ya enviado', $month->label(), $step));
        }

        $io->success(sprintf('Aviso enviado a %s.', implode(', ', $to)));

        return $this->didWork(sprintf('%s: %d sin aprobar, avisado a %d persona(s)', $month->label(), \count($pending), \count($to)));
    }

    /**
     * El escalón alcanzado un día del mes: el más alto que no lo supera. El día 1
     * siempre alcanza el primero, así que nunca hay "sin escalón".
     *
     * @param int $dayOfMonth Día del mes (1-31).
     * @return int Uno de {@see self::STEPS}.
     */
    public static function stepFor(int $dayOfMonth): int
    {
        $reached = array_filter(self::STEPS, static fn (int $step) => $step <= $dayOfMonth);

        return max($reached ?: [self::STEPS[0]]);
    }

    /**
     * Arma el correo del aviso.
     *
     * @param WorkMonth    $month   Mes sin aprobar.
     * @param list<array>  $pending Filas pendientes de {@see WorkMonthClosing::pendingFor()}.
     * @param list<string> $to      Destinatarios.
     * @return TemplatedEmail
     */
    private function buildMessage(WorkMonth $month, array $pending, array $to): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->to(...$to)
            ->subject(sprintf('CSA Vega · Falta aprobar el registro de jornada de %s', $month->label()))
            ->htmlTemplate('email/staff_month_close_reminder.html.twig')
            ->textTemplate('email/staff_month_close_reminder.txt.twig')
            ->context([
                'month_label' => $month->label(),
                'pending' => $pending,
                'close_url' => $this->urlGenerator->generate(
                    'staff_month_close',
                    ['year' => $month->year, 'month' => $month->month],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);
    }
}
