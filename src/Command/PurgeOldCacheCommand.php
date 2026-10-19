<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Borra las cachés que el despliegue dejó apartadas. Pensado para correr de
 * madrugada.
 *
 * El despliegue no borra la caché del servidor: la RENOMBRA (var/cache/prod →
 * var/cache/prod_old_<run>), porque borrarla por FTP tarda decenas de minutos
 * con la web escribiendo en ella y no puede con los ficheros que creó PHP. Lo
 * que el FTP no puede borrar sí lo puede borrar PHP, que es su dueño: por eso la
 * limpieza vive aquí y no en el despliegue.
 *
 * Sólo toca hermanas de la caché en uso con el sufijo «_old_»: nunca la propia
 * caché ni nada más de var/cache.
 */
#[AsCommand(name: 'app:purge-old-cache', description: 'Borra las cachés que el despliegue dejó apartadas.')]
class PurgeOldCacheCommand extends AbstractCronCommand
{
    public function __construct(
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No borra; sólo lista lo que borraría');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stale = $this->staleDirectories();

        if ($input->getOption('dry-run')) {
            $io->note($stale === []
                ? 'Dry-run: no hay cachés apartadas.'
                : sprintf('Dry-run: se borrarían %d caché(s) apartada(s): %s', \count($stale), implode(', ', array_map('basename', $stale))));

            return Command::SUCCESS;
        }

        if ($stale === []) {
            return $this->nothingToDo('No hay cachés apartadas');
        }

        $failures = array_filter(array_map($this->remove(...), $stale));
        if ($failures !== []) {
            // Lo que ni PHP puede borrar lo creó otro usuario del hosting: hay que
            // pedírselo al soporte. Se marca como fallo para que no pase inadvertido.
            $io->error(['No se han podido borrar del todo:', ...$failures]);

            return Command::FAILURE;
        }

        $io->success(sprintf('Borradas %d caché(s) apartada(s).', \count($stale)));

        return $this->didWork(sprintf('%d caché(s) apartada(s) borrada(s)', \count($stale)));
    }

    /**
     * Las cachés apartadas por el despliegue: hermanas de la caché en uso que se
     * llaman como ella seguida de «_old_».
     *
     * @return list<string> Rutas absolutas de los directorios.
     */
    private function staleDirectories(): array
    {
        $pattern = sprintf('%s/%s_old_*', \dirname($this->cacheDir), basename($this->cacheDir));

        return glob($pattern, \GLOB_ONLYDIR) ?: [];
    }

    /**
     * Borra una caché apartada: primero su contenido y luego el directorio vacío.
     *
     * No se le pasa el directorio entero a Filesystem::remove() porque éste lo
     * renombra a un «.!xxxx» oculto antes de vaciarlo y, si un fichero de dentro
     * no se deja borrar, no deshace el renombrado: la caché quedaba fuera del
     * patrón «_old_*» y la tarea siguiente salía en verde con la basura escondida.
     * Vaciándolo por dentro, lo que no se borre sigue en el «_old_*» y la tarea lo
     * vuelve a ver y a dar por fallido.
     *
     * @param string $directory Ruta absoluta del directorio.
     *
     * @return string|null El motivo si no se pudo borrar del todo; null si se borró.
     */
    private function remove(string $directory): ?string
    {
        try {
            $this->filesystem->remove(new \FilesystemIterator($directory, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS));
            $this->filesystem->remove($directory);

            return null;
        } catch (IOException $e) {
            return $e->getMessage();
        }
    }
}
