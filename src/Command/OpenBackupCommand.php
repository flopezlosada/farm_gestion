<?php

namespace App\Command;

use App\Service\Backup\SealedBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Descifra una copia de seguridad bajada de B2.
 *
 * Se ejecuta en la máquina de quien restaura, NUNCA en el servidor: la clave
 * privada no debe pisar el hosting. Se pide por teclado y sin eco para que no
 * quede en el historial de la terminal.
 *
 * Escribe el resultado junto al fichero, sin la extensión `.sealed`, y se niega a
 * pisar uno que ya exista.
 */
#[AsCommand(name: 'app:backup:open', description: 'Descifra una copia de seguridad bajada de B2 (pide la clave privada).')]
class OpenBackupCommand extends Command
{
    private const SEALED_SUFFIX = '.sealed';

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Fichero cifrado (termina en .sealed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('file');

        if (!str_ends_with($path, self::SEALED_SUFFIX) || !is_file($path)) {
            $io->error(sprintf('No encuentro un fichero %s en %s.', self::SEALED_SUFFIX, $path));

            return Command::INVALID;
        }
        $target = substr($path, 0, -\strlen(self::SEALED_SUFFIX));
        if (file_exists($target)) {
            $io->error(sprintf('Ya existe %s: no lo piso.', $target));

            return Command::FAILURE;
        }

        try {
            $plain = SealedBox::open((string) file_get_contents($path), (string) $io->askHidden('Clave privada (base64)'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        file_put_contents($target, $plain);
        $io->success(sprintf('Descifrado en %s.', $target));

        return Command::SUCCESS;
    }
}
