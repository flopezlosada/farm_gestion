<?php

namespace App\Command;

use App\Entity\PartnerEvent;
use App\Repository\PartnerEventRepository;
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

    /**
     * Tipos de PartnerEvent que cuentan como "cambio autoservicio
     * relevante para admin". Otros tipos (JOIN, LEAVE, etc.) ya tienen
     * sus propios flujos y no entran aquí.
     */
    private const RELEVANT_TYPES = [
        PartnerEvent::TYPE_BASKET_SKIP,
        PartnerEvent::TYPE_BASKET_UNSKIP,
        PartnerEvent::TYPE_NODE_CHANGE,
        PartnerEvent::TYPE_WEEK_SWAP,
    ];

    public function __construct(
        private readonly PartnerEventRepository $eventRepository,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Dirección de email del admin (obligatorio si no es dry-run)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Cuántos días hacia atrás incluir', self::DEFAULT_DAYS)
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Fecha desde la que incluir (YYYY-MM-DD). Sobrescribe --days')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los eventos que enviaría sin enviar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $to = $input->getOption('to');

        if (!$dryRun && !$to) {
            $io->error('Falta --to=email del admin (o usa --dry-run).');
            return Command::FAILURE;
        }

        $since = $this->resolveSince($input);

        $events = $this->eventRepository->findByTypesSince(self::RELEVANT_TYPES, $since);

        $io->section(sprintf('Eventos desde %s', $since->format('Y-m-d H:i')));

        if (empty($events)) {
            $io->note('Sin cambios en el período. No se envía nada.');
            return Command::SUCCESS;
        }

        $rows = array_map(
            fn (PartnerEvent $e) => [
                $e->getOccurredAt()->format('Y-m-d H:i'),
                $e->getPartner()->getSurname() . ', ' . $e->getPartner()->getName(),
                $this->humanType($e->getType(), $e->getPayload() ?? []),
                $this->humanDescription($e->getType(), $e->getPayload() ?? []),
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
                'rows' => array_map(fn (PartnerEvent $e) => $this->renderableRow($e), $events),
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

    /**
     * Convierte el tipo del evento a una etiqueta breve para humanos.
     * Tiene en cuenta el flag `cancelled` del payload para distinguir
     * un WEEK_SWAP de su reversión.
     */
    private function humanType(string $type, array $payload): string
    {
        return match ($type) {
            PartnerEvent::TYPE_BASKET_SKIP => 'No recoge cesta',
            PartnerEvent::TYPE_BASKET_UNSKIP => 'Vuelve a recoger',
            PartnerEvent::TYPE_NODE_CHANGE => 'Cambia de nodo',
            PartnerEvent::TYPE_WEEK_SWAP => ($payload['cancelled'] ?? false) ? 'Cancela cambio de viernes' : 'Cambia de viernes',
            default => $type,
        };
    }

    /**
     * Detalle textual con los datos del payload, listo para mostrar al admin.
     */
    private function humanDescription(string $type, array $payload): string
    {
        return match ($type) {
            PartnerEvent::TYPE_NODE_CHANGE => sprintf(
                '%s → %s',
                $payload['from_group_name'] ?? '—',
                $payload['to_group_name'] ?? '—',
            ),
            PartnerEvent::TYPE_WEEK_SWAP => sprintf(
                'del %s al %s',
                $payload['from_date'] ?? '—',
                $payload['to_date'] ?? '—',
            ),
            default => '',
        };
    }

    /**
     * Fila precomputada para el template del email — Twig 3 no puede invocar
     * closures pasados por contexto, así que precomputamos todo aquí.
     *
     * @return array{when:string, partner:string, type:string, detail:string}
     */
    private function renderableRow(PartnerEvent $e): array
    {
        $payload = $e->getPayload() ?? [];
        $partner = $e->getPartner();
        $name = trim(($partner->getSurname() ?? '') . ($partner->getName() ? ', ' . $partner->getName() : ''));

        return [
            'when' => $e->getOccurredAt()->format('d/m/Y H:i'),
            'partner' => $name !== '' ? $name : 'Sin nombre',
            'type' => $this->humanType($e->getType(), $payload),
            'detail' => $this->humanDescription($e->getType(), $payload),
        ];
    }
}
