<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * La limpieza de las cachés que el despliegue deja apartadas. Borra
 * directorios, así que lo que se vigila es que borre SÓLO las apartadas: nunca
 * la caché en uso ni otra cosa de var/cache que se le parezca.
 */
class PurgeOldCacheCommandTest extends KernelTestCase
{
    /** Directorios creados por el test, para dejarlo todo como estaba. */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $dir) {
            if (is_dir($dir)) {
                (new Filesystem())->chmod($dir, 0o755, 0o000, true);
            }
        }
        (new Filesystem())->remove($this->created);

        parent::tearDown();
    }

    /** Borra las apartadas con todo su árbol y deja la caché en uso y las vecinas. */
    public function testBorraSoloLasCachesApartadas(): void
    {
        self::bootKernel();
        $cacheDir = self::getContainer()->getParameter('kernel.cache_dir');
        $first = $this->staleCache('_old_111');
        $second = $this->staleCache('_old_222');
        $neighbour = $this->staleCache('_older');

        $tester = $this->commandTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        $this->assertDirectoryDoesNotExist($first);
        $this->assertDirectoryDoesNotExist($second);
        $this->assertDirectoryExists($neighbour, 'Sólo se borra lo que lleva el sufijo «_old_».');
        $this->assertDirectoryExists($cacheDir, 'La caché en uso no se toca nunca.');
    }

    /**
     * Lo que no se deja borrar —ficheros de otro dueño en el hosting— deja la
     * caché apartada con su nombre y la ejecución en rojo. Filesystem::remove()
     * renombraba el directorio a un «.!xxxx» oculto y no lo deshacía al fallar:
     * la siguiente pasada ya no lo veía y salía en verde con la basura dentro.
     */
    public function testLoQueNoSeDejaBorrarSigueVisibleYFalla(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Como root no hay fichero que se resista a borrarse.');
        }

        self::bootKernel();
        $cacheDir = self::getContainer()->getParameter('kernel.cache_dir');
        $stale = $this->staleCache('_old_444');
        chmod($stale . '/pools/system/AB/C', 0o555);

        $tester = $this->commandTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        $this->assertStringContainsString('Permission denied', $tester->getDisplay());
        $this->assertDirectoryExists($stale, 'La caché apartada conserva su nombre.');
        $this->assertSame([], glob(\dirname($cacheDir) . '/.!*'), 'Nada se esconde junto a la caché en uso.');

        $tester->execute(['--force' => true]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'La pasada siguiente lo sigue viendo.');
    }

    /** El dry-run lista y no borra. */
    public function testElDryRunNoBorra(): void
    {
        self::bootKernel();
        $stale = $this->staleCache('_old_333');

        $tester = $this->commandTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertDirectoryExists($stale);
        $this->assertStringContainsString(basename($stale), $tester->getDisplay());
    }

    /** Sin apartadas, sale bien sin hacer nada. */
    public function testSinCachesApartadasNoHaceNada(): void
    {
        self::bootKernel();

        $tester = $this->commandTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
    }

    /**
     * Una caché apartada como la deja el despliegue: hermana de la caché en uso,
     * con subdirectorios y ficheros dentro.
     *
     * @param string $suffix Lo que va detrás del nombre de la caché en uso.
     *
     * @return string Ruta absoluta del directorio creado.
     */
    private function staleCache(string $suffix): string
    {
        $cacheDir = self::getContainer()->getParameter('kernel.cache_dir');
        $dir = $cacheDir . $suffix;
        (new Filesystem())->dumpFile($dir . '/pools/system/AB/C/entry.php', '<?php return [];');
        $this->created[] = $dir;

        return $dir;
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:purge-old-cache'));
    }
}
