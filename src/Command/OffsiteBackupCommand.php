<?php

namespace App\Command;

use App\Service\Backup\OffsiteBackup;
use App\Service\Backup\SealedBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copia de seguridad fuera del hosting, cifrada y bloqueada contra el borrado:
 * un volcado de la base y los documentos que aún no estén fuera
 * ({@see OffsiteBackup}).
 *
 * POR QUÉ FUERA: el hosting guarda sus copias quince días y todo vive en un
 * mismo proveedor, mientras que las facturas y el libro hay que conservarlos
 * años.
 *
 * LA BASE VA PRIMERO: pesa poco y sin ella los documentos son ficheros sin dueño.
 *
 * LOS DOCUMENTOS TIENEN UN PRESUPUESTO DE TIEMPO ({@see self::DOCUMENT_BUDGET_SECONDS}).
 * La tarea corre dentro de una petición del servidor web, que tiene su propio
 * tiempo máximo, y la primera copia sube cientos de megas. Pasado el presupuesto
 * no se empieza ningún documento más: los que falten salen en la siguiente,
 * porque lo que ya está fuera se pregunta a B2 y no se vuelve a subir.
 */
#[AsCommand(name: 'app:offsite-backup', description: 'Copia de seguridad fuera del hosting: la base y los documentos, cifrados.')]
class OffsiteBackupCommand extends AbstractCronCommand
{
    /** Segundos a partir de los cuales no se empieza a subir otro documento. */
    private const DOCUMENT_BUDGET_SECONDS = 120;

    public function __construct(
        private readonly OffsiteBackup $backup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No sube nada; dice qué documentos faltan por subir');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);

        // Un fallo y no un "nada que hacer": sin libsodium no se puede arreglar
        // desde la configuración, hay que pedírselo al hosting.
        if (!SealedBox::isAvailable()) {
            $io->error('Este PHP no trae libsodium (ext-sodium): sin ella no se puede cifrar la copia.');

            return Command::FAILURE;
        }
        if (!$this->backup->isConfigured()) {
            $io->warning('La copia no está configurada: faltan B2_KEY_ID, B2_APPLICATION_KEY o BACKUP_PUBLIC_KEY.');

            return $this->nothingToDo('Copia sin configurar');
        }

        $now = new \DateTimeImmutable();
        try {
            if ($input->getOption('dry-run')) {
                $io->note(sprintf('Dry-run: se volcaría la base y faltan %d documentos por subir.', \count($this->backup->pendingDocuments())));

                return Command::SUCCESS;
            }

            $database = $this->backup->backUpDatabase($now);
            $io->text(sprintf('Base: %s (%s).', $database['name'], $this->size($database['bytes'])));

            $documents = $this->backup->backUpDocuments(
                $this->backup->pendingDocuments(),
                $now,
                $started + self::DOCUMENT_BUDGET_SECONDS,
            );
        } catch (\RuntimeException|\LogicException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $summary = sprintf(
            'Base copiada; %d documentos subidos (%s)%s.',
            $documents['uploaded'],
            $this->size($documents['bytes']),
            $documents['left'] > 0 ? sprintf(', quedan %d para la siguiente', $documents['left']) : '',
        );
        if ($documents['unreadable'] !== []) {
            $io->error(sprintf('%s No se pueden leer: %s', $summary, implode(', ', $documents['unreadable'])));

            return Command::FAILURE;
        }
        $io->success($summary);

        return $this->didWork($summary);
    }

    /** Un tamaño en bytes, legible. */
    private function size(int $bytes): string
    {
        return $bytes < 1048576
            ? sprintf('%s KB', number_format($bytes / 1024, 0, ',', '.'))
            : sprintf('%s MB', number_format($bytes / 1048576, 1, ',', '.'));
    }
}
