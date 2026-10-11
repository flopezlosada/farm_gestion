<?php

namespace App\Service\Telegram;

use App\Service\Storage\PrivateFileStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * El archivo de lo que llega al bot. Cada envío guarda aquí su propia copia del
 * fichero, aparte de la que se lleve el apartado que lo recibe: así, si el bot se
 * equivocó de apartado, el fichero sigue a mano para mandarlo al bueno.
 *
 * Fuera del docroot como el de facturas: son facturas, fotos de la huerta, notas
 * de voz… y no se sirven por ningún enlace público.
 */
final class TelegramFileStore extends PrivateFileStore
{
    /**
     * @param string           $directory  Carpeta del archivo del bot.
     * @param SluggerInterface $slugger    Para limpiar el nombre original.
     * @param Filesystem       $filesystem Para copiar y borrar.
     */
    public function __construct(
        #[Autowire('%app.telegram_files_dir%')]
        string $directory,
        SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct($directory, $slugger, $filesystem);
    }

    /**
     * Guarda una COPIA del fichero: el original se queda donde estaba, para quien
     * lo vaya a usar después.
     *
     * @param string $path         Fichero en disco.
     * @param string $originalName Nombre con que llegó.
     *
     * @return string Nombre guardado.
     */
    public function keepCopy(string $path, string $originalName): string
    {
        $copy = $this->temporaryPath();
        $this->filesystem->copy($path, $copy, true);

        return $this->storeFile(new File($copy), $originalName);
    }

    /**
     * Copia temporal de un fichero del archivo, para dársela a un apartado que se
     * la puede llevar (moverla) sin tocar la del archivo. Quien la pide la borra.
     *
     * @param string $name Nombre guardado.
     *
     * @return string|null Ruta de la copia, o null si el fichero ya no está.
     */
    public function temporaryCopy(string $name): ?string
    {
        $path = $this->pathTo($name);
        if ($path === null) {
            return null;
        }
        $copy = $this->temporaryPath();
        $this->filesystem->copy($path, $copy, true);

        return $copy;
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tgs-');
        if ($path === false) {
            throw new \RuntimeException('No se puede crear un fichero temporal.');
        }

        return $path;
    }
}
