<?php

namespace App\Command;

use App\Service\Backup\B2Client;
use App\Service\Backup\SealedBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copia de seguridad fuera del hosting, cifrada y bloqueada contra el borrado.
 *
 * POR QUÉ FUERA: el hosting guarda sus copias quince días y todo vive en un
 * mismo proveedor, mientras que las facturas y el libro hay que conservarlos
 * años.
 *
 * DE MOMENTO ES UNA PRUEBA. Sube un fichero pequeño, cifrado y bloqueado un día,
 * para comprobar en el servidor real lo que no se puede comprobar en local: que
 * PHP trae libsodium, que el hosting deja salir hacia B2 y que la clave tiene
 * justo los permisos que debe. Las facturas y el volcado de la base vienen
 * después, sobre esta misma tarea.
 */
#[AsCommand(name: 'app:offsite-backup', description: 'Copia de seguridad fuera del hosting (de momento, un fichero de prueba).')]
class OffsiteBackupCommand extends AbstractCronCommand
{
    /** Carpeta del bucket donde caen las pruebas. */
    private const PROBE_PREFIX = 'prueba/';

    /** Lo justo para ver el bloqueo en la web de B2 y que caduque solo. */
    private const PROBE_RETENTION = '+1 day';

    public function __construct(
        private readonly B2Client $b2,
        private readonly SealedBox $sealer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No sube nada; sólo comprueba que está todo configurado');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Un fallo y no un "nada que hacer": sin libsodium no se puede arreglar
        // desde la configuración, hay que pedírselo al hosting.
        if (!SealedBox::isAvailable()) {
            $io->error('Este PHP no trae libsodium (ext-sodium): sin ella no se puede cifrar la copia.');

            return Command::FAILURE;
        }
        if (!$this->b2->isConfigured() || !$this->sealer->isConfigured()) {
            $io->warning('La copia no está configurada: faltan B2_KEY_ID, B2_APPLICATION_KEY o BACKUP_PUBLIC_KEY.');

            return $this->nothingToDo('Copia sin configurar');
        }

        $now = new \DateTimeImmutable();
        $fileName = self::PROBE_PREFIX . $now->format('Ymd-His') . '.txt.sealed';
        $retainUntil = $now->modify(self::PROBE_RETENTION);

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Dry-run: libsodium y claves presentes; se subiría %s bloqueado hasta %s.', $fileName, $retainUntil->format('Y-m-d H:i')));

            return Command::SUCCESS;
        }

        try {
            $sealed = $this->sealer->seal(sprintf("Prueba de copia de seguridad generada el %s.\n", $now->format(\DATE_ATOM)));
            $file = $this->b2->uploadLocked($fileName, $sealed, $retainUntil);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Subido %s al bucket %s, bloqueado (governance) hasta %s. Id: %s',
            $file['fileName'],
            $file['bucket'],
            $retainUntil->format('Y-m-d H:i'),
            $file['fileId'],
        ));

        return $this->didWork(sprintf('Prueba subida: %s', $file['fileName']));
    }
}
