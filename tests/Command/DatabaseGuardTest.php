<?php

namespace App\Tests\Command;

use App\Command\DatabaseGuard;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la guarda técnica de BBDD para comandos mutantes: la lista
 * blanca de clones desechables (assertDisposable) y la lista negra de BBDD
 * protegidas (assertNotProtected). Es la red que evita que un DATABASE_URL
 * despistado corrompa la golden — si estos nombres cambian de criterio,
 * que sea a propósito y no por accidente.
 */
class DatabaseGuardTest extends TestCase
{
    /**
     * Construye una conexión simulada que reporta el nombre de BBDD dado.
     *
     * @param string $database nombre que devolverá getDatabase()
     */
    private function connectionFor(string $database): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn($database);

        return $connection;
    }

    /**
     * @dataProvider clonesDesechables
     */
    public function testAssertDisposableAceptaClones(string $db): void
    {
        DatabaseGuard::assertDisposable($this->connectionFor($db), ['play', 'battery']);

        $this->addToAssertionCount(1); // no lanzó: la guarda deja pasar el clon
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clonesDesechables(): array
    {
        return [
            'db_play'    => ['db_play'],
            'db_battery' => ['db_battery'],
        ];
    }

    /**
     * @dataProvider bbddNoDesechables
     */
    public function testAssertDisposableRechazaLoQueNoEsClon(string $db): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no parece un clon desechable/');

        DatabaseGuard::assertDisposable($this->connectionFor($db), ['play', 'battery']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bbddNoDesechables(): array
    {
        return [
            'sandbox db'        => ['db'],
            'golden'            => ['db_prod_snapshot'],
            'tests'             => ['db_test'],
            'nombre vacío'      => [''],
        ];
    }

    /**
     * @dataProvider bbddProtegidas
     */
    public function testAssertNotProtectedRechazaProtegidas(string $db): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/protegida/');

        DatabaseGuard::assertNotProtected($this->connectionFor($db));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bbddProtegidas(): array
    {
        return [
            'golden'           => ['db_prod_snapshot'],
            'cualquier prod'   => ['csa_prod'],
            'cualquier golden' => ['db_golden'],
        ];
    }

    /**
     * @dataProvider bbddLibres
     */
    public function testAssertNotProtectedDejaPasarSandboxYClones(string $db): void
    {
        DatabaseGuard::assertNotProtected($this->connectionFor($db));

        $this->addToAssertionCount(1); // no lanzó: sandbox y clones pasan
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bbddLibres(): array
    {
        return [
            'sandbox db' => ['db'],
            'db_play'    => ['db_play'],
            'db_battery' => ['db_battery'],
            'db_test'    => ['db_test'],
            'csastaging' => ['csastaging'],
        ];
    }
}
