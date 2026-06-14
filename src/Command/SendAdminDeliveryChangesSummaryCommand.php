<?php

namespace App\Command;

use App\Entity\PartnerEvent;
use App\Repository\PartnerEventRepository;
use App\Service\AppSettings;
use App\Service\Email\DeliveryChangeFormatter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Resumen periódico a la administración con los cambios autoservicio
 * que han hecho los socixs en el último período: saltar/recuperar
 * cesta, cambiar de nodo puntualmente, pedir cambio de viernes.
 *
 * Pensado para correr por cron (default lunes 07:00, después de
 * generate-weekly-delivery). El admin ve el digest sin tener que
 * entrar al feed.
 *
 * Si no hay cambios en el período, no envía nada (el admin no
 * recibe correo vacío).
 */
#[AsCommand(name: 'app:send-admin-delivery-changes-summary', description: 'Resumen periódico al admin con los cambios autoservicio de los socixs.')]
class SendAdminDeliveryChangesSummaryCommand extends Command
{
    private const DEFAULT_DAYS = 7;

    public function __construct(
        private readonly PartnerEventRepository $eventRepository,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly DeliveryChangeFormatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Dirección de email del admin (obligatorio si no es dry-run)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Cuántos días hacia atrás incluir', self::DEFAULT_DAYS)
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Fecha desde la que incluir (YYYY-MM-DD). Sobrescribe --days')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los eventos que enviaría sin enviar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $to = $input->getOption('to');

        // Gate de la tarea programada: apagada en /gestion/settings, la tarea ni
        // siquiera reúne los eventos (verde para no disparar alertas del cron).
        // El dry-run y --force (ejecución manual explícita) la saltan.
        if (!$dryRun && !$input->getOption('force') && !$this->settings->getBool(AppSettings::CRON_ADMIN_DELIVERY_SUMMARY)) {
            $io->warning('La tarea programada del resumen a administración está desactivada en /gestion/settings. No se ejecuta.');
            return Command::SUCCESS;
        }

        // Toggle de configuración: con el envío apagado el cron no manda nada
        // (verde para no disparar alertas). El dry-run sigue funcionando.
        if (!$dryRun && !$this->settings->getBool(AppSettings::EMAIL_ADMIN_DELIVERY_SUMMARY)) {
            $io->warning('El resumen a administración está desactivado en /gestion/settings. No se envía nada.');
            return Command::SUCCESS;
        }

        if (!$dryRun && !$to) {
            $io->error('Falta --to=email del admin (o usa --dry-run).');
            return Command::FAILURE;
        }

        $since = $this->resolveSince($input);

        $events = $this->eventRepository->findByTypesSince(DeliveryChangeFormatter::RELEVANT_TYPES, $since);

        $io->section(sprintf('Eventos desde %s', $since->format('Y-m-d H:i')));

        if (empty($events)) {
            $io->note('Sin cambios en el período. No se envía nada.');
            return Command::SUCCESS;
        }

        $rows = array_map(
            fn (PartnerEvent $e) => [
                $e->getOccurredAt()->format('Y-m-d H:i'),
                $e->getPartner()->getSurname() . ', ' . $e->getPartner()->getName(),
                $this->formatter->humanType($e->getType(), $e->getPayload() ?? []),
                $this->formatter->humanDescription($e->getType(), $e->getPayload() ?? []),
            ],
            $events
        );
        $io->table(['Cuándo', 'Socix', 'Tipo', 'Detalle'], $rows);

        if ($dryRun) {
            $io->success(sprintf('Dry-run: %d evento(s) listado(s). Sin envío.', count($events)));
            return Command::SUCCESS;
        }

        $message = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('CSA Vega · Cambios de socixs desde %s', $since->format('d/m/Y')))
            ->htmlTemplate('email/admin_delivery_changes_summary.html.twig')
            ->textTemplate('email/admin_delivery_changes_summary.txt.twig')
            ->context([
                'since' => $since,
                'rows' => array_map(fn (PartnerEvent $e) => $this->formatter->renderableRow($e), $events),
            ]);

        $this->mailer->send($message);
        $io->success(sprintf('Enviado a %s · %d evento(s).', $to, count($events)));

        return Command::SUCCESS;
    }

    private function resolveSince(InputInterface $input): \DateTimeImmutable
    {
        $sinceOpt = $input->getOption('since');
        if ($sinceOpt !== null) {
            return new \DateTimeImmutable($sinceOpt);
        }

        $days = max(1, (int) $input->getOption('days'));
        return new \DateTimeImmutable(sprintf('-%d days', $days));
    }
}
