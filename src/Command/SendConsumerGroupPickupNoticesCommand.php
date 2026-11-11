<?php

namespace App\Command;

use App\Service\AppSettings;
use App\Service\ConsumerGroup\ConsumerGroupPickupNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Avisa a cada familia con un pedido del grupo de consumo por recoger
 * ({@see ConsumerGroupPickupNotifier}).
 *
 * Pensado para correr a diario, como el recordatorio de la cesta y con su misma
 * antelación ({@see AppSettings::PICKUP_REMINDER_DAYS_BEFORE}): avisa de lo que se
 * recoge EXACTAMENTE «hoy + N días». Cada nodo recoge su día, así que el aviso de
 * Madrid y el de la Sierra salen en días distintos. Con --date se fuerza una fecha
 * física concreta (pruebas y reenvíos).
 *
 * --dry-run muestra a quién, dónde y qué día avisaría, sin enviar nada.
 */
#[AsCommand(name: 'app:send-consumer-group-pickup-notices', description: 'Aviso de recogida de los pedidos del grupo de consumo, por nodo y fecha física.')]
class SendConsumerGroupPickupNoticesCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly ConsumerGroupPickupNotifier $notifier,
        private readonly AppSettings $settings,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Fecha física de recogida (YYYY-MM-DD); ignora la antelación')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el interruptor de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Reenvía el correo aunque ya conste emitido')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, sólo lista destinatarios');
    }

    /**
     * {@inheritdoc}
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = $this->optionalDate($input, $io);
        if ($target === false) {
            return Command::FAILURE;
        }
        $target ??= (new \DateTimeImmutable('today'))
            ->modify(sprintf('+%d days', $this->settings->getInt(AppSettings::PICKUP_REMINDER_DAYS_BEFORE)));

        $pickups = $this->notifier->pickupsOn($target);
        $io->section(sprintf('Recogidas del grupo de consumo el %s', $target->format('Y-m-d')));

        if ($pickups === []) {
            $io->note('Nadie recoge un pedido del grupo de consumo ese día. No se envía nada.');

            return $this->nothingToDo(sprintf('Ningún pedido se recoge el %s', $target->format('Y-m-d')));
        }

        $io->table(
            ['Familia', 'Pedido', 'Nodo', 'Día', 'Importe'],
            array_map(static fn (array $pickup): array => [
                (string) $pickup['order']->getPartner(),
                (string) $pickup['order']->getRound()?->getTitle(),
                $pickup['node']->getName(),
                $pickup['date']->format('Y-m-d'),
                number_format($pickup['order']->getTotal(), 2, ',', '.') . ' €',
            ], $pickups),
        );

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d pedido(s). No se ha enviado nada.', count($pickups)));

            return Command::SUCCESS;
        }

        $sent = $this->notifier->notify($pickups, (bool) $input->getOption('resend'));
        $summary = sprintf(
            '%d pedido(s) el %s · %d correo(s) · %d aviso(s) al móvil · %d en la bandeja',
            count($pickups),
            $target->format('Y-m-d'),
            $sent['email'],
            $sent['push'],
            $sent['inbox'],
        );
        $io->success($summary);

        // Una segunda pasada del reloj lo encuentra todo ya avisado: es lo normal,
        // no una avería, y el registro tiene que distinguirlo de haber trabajado.
        return array_sum($sent) > 0 ? $this->didWork($summary) : $this->nothingToDo($summary . ' (ya avisados)');
    }
}
