<?php

namespace App\Command;

use App\Entity\Obligation;
use App\Service\Notification\StaffAudience;
use App\Service\Obligation\ObligationWatch;
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
 * Avisa por correo de lo que está a punto de caducar: convenios de tierra,
 * registros oficiales, pólizas, concesiones, el mandato de la junta.
 *
 * Es la única pieza de este módulo que hace algo por su cuenta, y existe porque
 * el problema no es que no se sepa que las cosas caducan: es que nadie mira. Un
 * registro que hay que ir a consultar ya lo hay —está en el Dropbox— y no ha
 * evitado que la renovación de la junta lleve años sin archivar.
 *
 * UN CORREO POR OBLIGACIÓN Y ESCALÓN, no un resumen. Cada vencimiento tiene su
 * gestión y muchas veces su responsable, y un digest con cinco cosas de cinco
 * personas distintas no es de nadie. Son unos pocos al año: el ruido no es el
 * riesgo aquí, el silencio sí.
 *
 * La idempotencia se ancla en la FECHA DE VENCIMIENTO, no en la de hoy. Así cada
 * escalón avisa una sola vez, y cuando la obligación se renueva —fecha nueva—
 * los escalones vuelven a contar solos para el periodo siguiente, sin que nadie
 * tenga que reactivar nada.
 */
#[AsCommand(name: 'app:send-obligation-notices', description: 'Avisa de convenios, registros y seguros próximos a caducar.')]
class SendObligationNoticesCommand extends AbstractCronCommand
{
    /** Clase de efecto en el guardián de idempotencia y en la bitácora de avisos. */
    private const KIND = 'obligation_notice';

    public function __construct(
        private readonly ObligationWatch $watch,
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
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Reenvía aunque el aviso ya conste emitido')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, sólo lista qué avisaría');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $date = $this->optionalDate($input, $io);
        if ($date === false) {
            return Command::FAILURE;
        }
        $today = $date ?? new \DateTimeImmutable('today');

        $notices = $this->watch->dueNotices($today);
        if ($notices === []) {
            // Se dice también POR PANTALLA y no sólo en el registro: en el cron
            // del hosting, `var/log/cron.log` es lo único que se puede leer sin
            // SSH, y un comando que no escribe nada hace indistinguible "no
            // había nada que avisar" de "no llegó a ejecutarse".
            $io->note('Ninguna obligación cruza un escalón de aviso.');

            return $this->nothingToDo('ninguna obligación cruza un escalón de aviso');
        }

        $recipients = $this->staff->emailsWithRole('ROLE_GESTION_VENCIMIENTOS');

        $io->section(sprintf('%d obligación(es) en aviso al %s', count($notices), $today->format('d/m/Y')));

        $sent = 0;
        $silenced = 0;

        foreach ($notices as $notice) {
            /** @var Obligation $obligation */
            $obligation = $notice['obligation'];
            $to = $this->recipientsFor($obligation, $recipients);

            $io->writeln(sprintf(
                '· %s — %s (escalón %d d)%s',
                $obligation->getName(),
                $this->watch->urgencyLabel($notice['days_left']),
                $notice['threshold'],
                $to === [] ? ' → SIN DESTINATARIO' : ' → ' . implode(', ', $to),
            ));

            if ($to === []) {
                // No se apunta como emitido: el día que alguien con el permiso
                // tenga correo, este aviso tiene que salir. Darlo por enviado
                // aquí lo perdería para siempre, que es el modo de fallo que ya
                // mordió con el recordatorio de las compartidas.
                ++$silenced;
                continue;
            }

            if ($input->getOption('dry-run')) {
                continue;
            }

            $emitted = $this->emitOnce(
                self::KIND,
                fn () => $this->mailer->send($this->buildMessage($obligation, $notice, $to)),
                $input,
                reference: sprintf('obligation-%d:%d', $obligation->getId(), $notice['threshold']),
                on: $obligation->expiresOn(),
                target: implode(', ', $to),
            );

            if ($emitted) {
                ++$sent;
            }
        }

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        if ($silenced > 0) {
            $io->warning(sprintf(
                '%d aviso(s) sin destinatario: nadie con permiso de vencimientos tiene correo, y la obligación tampoco tiene responsable con dirección.',
                $silenced
            ));
        }

        if ($sent === 0) {
            $io->note('Todas avisadas ya: no se repite ningún correo.');

            return $this->nothingToDo(sprintf(
                '%d en aviso, todas avisadas ya%s',
                count($notices),
                $silenced > 0 ? sprintf(' · ⚠ %d sin destinatario', $silenced) : '',
            ));
        }

        $io->success(sprintf('%d aviso(s) enviados.', $sent));

        return $this->didWork(sprintf(
            '%d aviso(s) de vencimiento%s',
            $sent,
            $silenced > 0 ? sprintf(' · ⚠ %d sin destinatario', $silenced) : '',
        ));
    }

    /**
     * A quién va el aviso de esta obligación: el equipo con permiso de
     * vencimientos y, si lo tiene, quien la lleva.
     *
     * El responsable se suma en vez de sustituir: que alguien esté nombrado no
     * significa que el resto no deba enterarse, y si esa persona se va de
     * vacaciones el aviso no puede quedarse en su bandeja.
     *
     * @param Obligation    $obligation Obligación que vence.
     * @param list<string>  $team       Correos del equipo con permiso.
     * @return list<string>
     */
    private function recipientsFor(Obligation $obligation, array $team): array
    {
        $responsible = $obligation->getResponsible()?->getEmail();

        if ($responsible === null || $responsible === '') {
            return $team;
        }

        return array_values(array_unique([...$team, $responsible]));
    }

    /**
     * Arma el correo del aviso.
     *
     * @param Obligation                                                   $obligation Obligación que vence.
     * @param array{obligation: Obligation, threshold: int, days_left: int} $notice     Escalón alcanzado.
     * @param list<string>                                                 $to         Destinatarios.
     */
    private function buildMessage(Obligation $obligation, array $notice, array $to): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->to(...$to)
            ->subject(sprintf(
                'CSA Vega · %s %s',
                $obligation->getName(),
                $this->watch->urgencyLabel($notice['days_left']),
            ))
            ->htmlTemplate('email/obligation_notice.html.twig')
            ->textTemplate('email/obligation_notice.txt.twig')
            ->context([
                'obligation' => $obligation,
                'days_left' => $notice['days_left'],
                'urgency' => $this->watch->urgencyLabel($notice['days_left']),
                'expires_on' => $obligation->expiresOn(),
                'obligation_url' => $this->urlGenerator->generate(
                    'obligation_show',
                    ['id' => $obligation->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);
    }
}
