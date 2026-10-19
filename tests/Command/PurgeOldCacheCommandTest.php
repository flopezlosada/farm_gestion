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
