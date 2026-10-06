<?php

namespace App\Command;

use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Invoice\InvoiceReadQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lee las facturas recibidas que siguen en cola. Pensado para correr cada hora.
 *
 * Es el reintento: lo normal es que una factura se lea en el momento de subirla
 * ({@see \App\EventListener\InvoiceReadListener}). Lo que llega aquí es lo que
 * entonces falló porque el servicio estaba saturado o sin cupo.
 */
#[AsCommand(name: 'app:read-received-invoices', description: 'Lee las facturas recibidas que siguen en cola.')]
class ReadReceivedInvoicesCommand extends AbstractCronCommand
{
    /**
     * Facturas por pasada. Con la pausa entre lecturas son algo más de un minuto,
     * y es más de lo que entra en un trimestre normal en un solo día.
     */
    private const BATCH = 20;

    public function __construct(
        private readonly InvoiceReadQueue $queue,
        private readonly ReceivedInvoiceRepository $invoices,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Facturas como mucho en esta pasada', (string) self::BATCH)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No lee nada; sólo cuenta lo que hay en cola');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        if ($limit < 1) {
            $io->error('--limit debe ser un entero positivo.');

            return Command::INVALID;
        }

        if (!$this->queue->isEnabled()) {
            $io->warning('La lectura automática no está configurada (falta GEMINI_API_KEY).');

            return $this->nothingToDo('Lectura sin configurar');
        }

        if ($input->getOption('dry-run')) {
            $due = \count($this->invoices->findDue(new \DateTimeImmutable(), $limit));
            $io->note(sprintf('Dry-run: se intentarían leer %d factura(s).', $due));

            return Command::SUCCESS;
        }

        $done = $this->queue->process($limit);
        $total = array_sum($done);
        $io->success(sprintf('Leídas %d, aplazadas %d, a mano %d.', $done['read'], $done['postponed'], $done['unreadable']));

        return $total > 0
            ? $this->didWork(sprintf('%d leída(s), %d aplazada(s), %d a mano', $done['read'], $done['postponed'], $done['unreadable']))
            : $this->nothingToDo('Ninguna factura en cola');
    }
}
