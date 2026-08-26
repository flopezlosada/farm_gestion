<?php

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Genera el par de claves VAPID con el que se firman los avisos push.
 *
 * Se ejecuta UNA vez por instalación y su salida se pega en el `.env.local` del
 * servidor. Cambiar las claves invalida todas las suscripciones existentes: los
 * navegadores quedan suscritos contra la clave vieja y hay que volver a pedirles
 * permiso uno a uno, cosa que no va a hacer nadie. Así que se generan una vez y
 * no se tocan.
 *
 * No escribe el `.env.local` por su cuenta a propósito: en producción no hay
 * SSH, el fichero se sube por FTP, y un comando que reescribe la configuración
 * del servidor a ciegas es justo lo que no queremos.
 */
#[AsCommand(
    name: 'app:push-generate-vapid-keys',
    description: 'Genera un par de claves VAPID para los avisos push.',
)]
class PushGenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keys = VAPID::createVapidKeys();

        $io->title('Claves VAPID');
        $io->text('Pega estas dos líneas en el <info>.env.local</info> del servidor. La privada NO va al repo.');
        $io->newLine();
        $io->writeln(sprintf('VAPID_PUBLIC_KEY=%s', $keys['publicKey']));
        $io->writeln(sprintf('VAPID_PRIVATE_KEY=%s', $keys['privateKey']));
        $io->newLine();
        $io->warning('Si estas claves cambian, todos los navegadores suscritos dejan de recibir avisos y hay que volver a pedirles permiso.');

        return Command::SUCCESS;
    }
}
