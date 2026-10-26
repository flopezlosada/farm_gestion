<?php

namespace App\Command;

use App\Service\Accounting\Invoice\InvoiceMailbox;
use App\Service\Accounting\Invoice\MailedInvoiceIntake;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recoge las facturas que han llegado al buzón de facturas. Pensado para correr una
 * vez al día: una factura no corre prisa por horas.
 *
 * Sólo las deja en la bandeja, en cola. Las lee la tarea que reintenta la lectura
 * ({@see ReadReceivedInvoicesCommand}), que corre cada hora: si un día llegan muchas
 * y se acaba el cupo de lectura, las termina sola los días siguientes.
 */
#[AsCommand(name: 'app:fetch-invoice-mail', description: 'Recoge las facturas que han llegado al buzón de facturas.')]
class FetchInvoiceMailCommand extends AbstractCronCommand
{
    /**
     * @param InvoiceMailbox      $mailbox El buzón.
     * @param MailedInvoiceIntake $intake  Saca las facturas de cada correo.
     */
    public function __construct(
        private readonly InvoiceMailbox $mailbox,
        private readonly MailedInvoiceIntake $intake,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No recoge nada; sólo cuenta los correos que esperan');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->mailbox->isConfigured()) {
            $io->warning('El buzón de facturas no está configurado: faltan INVOICE_MAILBOX_HOST, INVOICE_MAILBOX_USER o INVOICE_MAILBOX_PASSWORD.');

            return $this->nothingToDo('Buzón sin configurar');
        }

        try {
            if ($input->getOption('dry-run')) {
                $io->note(sprintf('Dry-run: hay %d correo(s) esperando en el buzón.', $this->mailbox->pending()));

                return Command::SUCCESS;
            }

            $done = $this->mailbox->drain($this->intake->take(...));
        } catch (\Throwable $e) {
            $io->error('No se pudo leer el buzón de facturas: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $summary = sprintf('%d factura(s) de %d correo(s), %d a revisar a mano', $done['invoices'], $done['messages'], $done['review']);
        if ($done['failed'] > 0) {
            $io->error(sprintf('%s; %d correo(s) fallaron y siguen en la bandeja de entrada.', $summary, $done['failed']));

            return Command::FAILURE;
        }
        $io->success($summary . '.');

        return $done['messages'] > 0 ? $this->didWork($summary) : $this->nothingToDo('Buzón vacío');
    }
}
