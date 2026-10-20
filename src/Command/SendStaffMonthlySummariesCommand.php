<?php

namespace App\Command;

use App\Entity\WorkMonthClose;
use App\Repository\WorkMonthCloseRepository;
use App\Security\WorkerUserProvisioner;
use App\Service\Staff\MonthlyTimesheetReport;
use App\Service\Staff\TimesheetPdfRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Manda a cada trabajador a tiempo parcial el resumen de su mes en cuanto se
 * aprueba. Es una obligación legal (art. 12.4.c del Estatuto): a quien trabaja a
 * tiempo parcial hay que entregarle cada mes el resumen de sus horas, y si no se
 * hace se presume que el contrato es a jornada completa.
 *
 * SÓLO MESES APROBADOS: el resumen que se entrega tiene que ser el definitivo.
 * Como un mes aprobado no cambia, el PDF se puede generar en el momento de
 * enviarlo sin guardarlo aparte. Si se reabre y se vuelve a aprobar, la
 * aprobación nueva es otra fila y sale otro resumen, que es lo que toca: el
 * anterior ya no vale.
 *
 * Una aprobación sale UNA vez (se ancla en su id). La ventana de días evita que
 * al encender la tarea se manden de golpe los resúmenes de meses viejos.
 */
#[AsCommand(name: 'app:send-staff-monthly-summaries', description: 'Manda el resumen del mes aprobado a quien trabaja a tiempo parcial.')]
class SendStaffMonthlySummariesCommand extends AbstractCronCommand
{
    /** Clase de efecto en el guardián de idempotencia y en la bitácora de avisos. */
    private const KIND = 'staff_monthly_summary';

    /** Hasta cuántos días atrás se mira la fecha de aprobación. */
    private const DEFAULT_WINDOW_DAYS = 45;

    public function __construct(
        private readonly WorkMonthCloseRepository $closes,
        private readonly WorkerUserProvisioner $provisioner,
        private readonly MonthlyTimesheetReport $report,
        private readonly TimesheetPdfRenderer $pdf,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Aprobaciones de los últimos N días', self::DEFAULT_WINDOW_DAYS)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Reenvía aunque el resumen ya conste enviado (para uno que no llegó)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, sólo lista qué mandaría');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $madrid = new \DateTimeZone('Europe/Madrid');
        $now = new \DateTimeImmutable('now', $madrid);
        $days = max(1, (int) $input->getOption('days'));

        $sent = 0;
        $already = 0;
        $undefined = [];
        $noEmail = [];

        foreach ($this->closes->findStandingApprovedSince($now->modify(sprintf('-%d days', $days))) as $close) {
            $worker = $close->getWorker();
            $label = sprintf('%s · %s', $worker->getName(), $close->getMonth()->label());

            if ($worker->getWorkdayType() === null) {
                // No se sabe si le toca. No se adivina: se dice, y la ficha y el
                // aviso de cierre lo piden.
                $undefined[] = $label;
                continue;
            }
            if (!$worker->isPartTime()) {
                continue;
            }

            $email = trim((string) $this->provisioner->userFor($worker)?->getEmail());
            if ($email === '') {
                // No se apunta: cuando tenga correo, su resumen tiene que salir.
                $noEmail[] = $label;
                continue;
            }

            $io->writeln(sprintf('· %s → %s', $label, $email));
            if ($input->getOption('dry-run')) {
                continue;
            }

            $emitted = $this->emitOnce(
                self::KIND,
                fn () => $this->mailer->send($this->buildMessage($close, $email, $madrid, $now)),
                $input,
                reference: sprintf('work-month-close-%d', $close->getId()),
                on: $close->getMonth()->start(),
                target: $email,
            );
            $emitted ? ++$sent : ++$already;
        }

        if ($undefined !== []) {
            $io->warning('Sin tipo de jornada en la ficha (no se sabe si toca mandarles el resumen): ' . implode('; ', $undefined));
        }
        if ($noEmail !== []) {
            $io->warning('A tiempo parcial y sin correo de acceso (hay que entregárselo a mano): ' . implode('; ', $noEmail));
        }

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $warnings = \count($undefined) + \count($noEmail);
        $suffix = $warnings > 0 ? sprintf(' · ⚠ %d sin poder enviar', $warnings) : '';

        if ($sent === 0) {
            $io->note('Ningún resumen nuevo que mandar.');

            return $this->nothingToDo(sprintf('nada nuevo%s%s', $already > 0 ? sprintf(' (%d ya enviados)', $already) : '', $suffix));
        }

        $io->success(sprintf('%d resumen(es) enviados.', $sent));

        return $this->didWork(sprintf('%d resumen(es) mensuales enviados%s', $sent, $suffix));
    }

    /**
     * Arma el correo con el PDF del mes adjunto.
     *
     * @param WorkMonthClose     $close  Aprobación del mes.
     * @param string             $to     Correo del trabajador.
     * @param \DateTimeZone      $madrid Zona del registro.
     * @param \DateTimeImmutable $now    Instante de generación del PDF.
     * @return TemplatedEmail
     */
    private function buildMessage(WorkMonthClose $close, string $to, \DateTimeZone $madrid, \DateTimeImmutable $now): TemplatedEmail
    {
        $worker = $close->getWorker();
        $month = $close->getMonth();
        $sheet = $this->report->forMonth($worker, $month->year, $month->month, $madrid, $now->setTime(0, 0));

        return (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('CSA Vega · Tu resumen de jornada de %s', $month->label()))
            ->htmlTemplate('email/staff_monthly_summary.html.twig')
            ->textTemplate('email/staff_monthly_summary.txt.twig')
            ->context([
                'worker_name' => $worker->getName(),
                'month_label' => $month->label(),
                'total_minutes' => $sheet['totalMinutes'],
                'worked_days' => $sheet['workedDays'],
            ])
            ->attach(
                $this->pdf->render($worker, $month->year, $month->month, $sheet, $now),
                $this->pdf->filename($worker, $month->year, $month->month),
                'application/pdf',
            );
    }
}
