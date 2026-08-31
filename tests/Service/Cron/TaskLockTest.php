<?php

namespace App\Tests\Service\Cron;

use App\Service\Cron\TaskLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El cerrojo de no solapamiento, contra MySQL de verdad.
 *
 * Hay un detalle que obliga a montar los tests así: los bloqueos con nombre de
 * MySQL son REENTRANTES para la misma conexión, o sea que pedir el mismo cerrojo
 * dos veces desde el mismo sitio lo concede las dos. Probar el bloqueo exige por
 * tanto una SEGUNDA conexión que haga de "el otro proceso", que es lo que se
 * simula aquí.
 */
class TaskLockTest extends KernelTestCase
{
    private const TASK = 'cron.test_lock';

    /** Conexión que hace de segundo proceso. */
    private ?Connection $otherProcess = null;

    /**
     * Cierra la conexión del "otro proceso", que libera cualquier cerrojo que se
     * haya quedado tomado si un test falla a mitad.
     */
    protected function tearDown(): void
    {
        $this->otherProcess?->close();
        $this->otherProcess = null;

        parent::tearDown();
    }

    /**
     * Sin nadie ejecutando la tarea, el cerrojo se concede.
     */
    public function testSeConcedeSiNadieLoTiene(): void
    {
        self::bootKernel();
        $lock = $this->lock();

        $this->assertTrue($lock->acquire(self::TASK));

        $lock->release(self::TASK);
    }

    /**
     * Con otro proceso dentro de la misma tarea, el cerrojo se niega. Es lo que
     * impide que dos ticks —o dos relojes en paralelo— congelen el listado dos
     * veces y dupliquen el reparto.
     */
    public function testSeNiegaSiOtroProcesoLoTiene(): void
    {
        self::bootKernel();
        $this->takeLockFromOtherProcess(self::TASK);

        $this->assertFalse($this->lock()->acquire(self::TASK), 'Con la tarea ya en marcha, el cerrojo debe negarse.');
    }

    /**
     * En cuanto el otro proceso lo suelta, la tarea vuelve a poder ejecutarse:
     * el cerrojo no deja la tarea inutilizada.
     */
    public function testSeVuelveAConcederCuandoElOtroProcesoLoSuelta(): void
    {
        self::bootKernel();
        $lock = $this->lock();
        $this->takeLockFromOtherProcess(self::TASK);
        $this->assertFalse($lock->acquire(self::TASK));

        $this->releaseLockFromOtherProcess(self::TASK);

        $this->assertTrue($lock->acquire(self::TASK));
        $lock->release(self::TASK);
    }

    /**
     * El nombre del cerrojo va prefijado con el de la base de datos, porque en
     * MySQL los bloqueos con nombre son globales al SERVIDOR: en un hosting
     * compartido, sin prefijo, otra aplicación con la misma clave de tarea nos
     * bloquearía. Se comprueba tomando el nombre SIN prefijo desde el otro
     * proceso: no debe estorbar.
     */
    public function testElNombreVaPrefijadoConLaBaseDeDatos(): void
    {
        self::bootKernel();
        $this->takeLock(self::TASK);

        $this->assertTrue(
            $this->lock()->acquire(self::TASK),
            'Un cerrojo con el mismo nombre pero sin el prefijo de la base de datos no debe bloquear esta tarea.'
        );

        $this->lock()->release(self::TASK);
    }

    /**
     * Servicio real del contenedor de test.
     */
    private function lock(): TaskLock
    {
        return self::getContainer()->get(TaskLock::class);
    }

    /**
     * Toma, desde la conexión que hace de segundo proceso, el cerrojo con el
     * MISMO nombre que compondría {@see TaskLock} (con prefijo de base de datos).
     *
     * @param string $task Clave de la tarea.
     */
    private function takeLockFromOtherProcess(string $task): void
    {
        $this->takeLock($task, prefixed: true);
    }

    /**
     * Suelta el cerrojo tomado por el segundo proceso.
     *
     * @param string $task Clave de la tarea.
     */
    private function releaseLockFromOtherProcess(string $task): void
    {
        $this->connectionOfOtherProcess()->fetchOne(
            'SELECT RELEASE_LOCK(?)',
            [$this->database() . ':' . $task]
        );
    }

    /**
     * Toma un cerrojo desde el segundo proceso, con o sin el prefijo de la base
     * de datos.
     *
     * @param string $task     Clave de la tarea.
     * @param bool   $prefixed ¿Con el prefijo que usa TaskLock?
     */
    private function takeLock(string $task, bool $prefixed = false): void
    {
        $name = $prefixed ? $this->database() . ':' . $task : $task;
        $granted = $this->connectionOfOtherProcess()->fetchOne('SELECT GET_LOCK(?, 0)', [$name]);

        $this->assertSame(1, (int) $granted, 'El segundo proceso debe poder tomar el cerrojo para que el test tenga sentido.');
    }

    /**
     * Conexión independiente a la misma base de datos, que hace de otro proceso.
     * Se construye con los parámetros de la conexión de la aplicación para
     * apuntar exactamente a la misma.
     */
    private function connectionOfOtherProcess(): Connection
    {
        return $this->otherProcess ??= DriverManager::getConnection(
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams()
        );
    }

    /**
     * Nombre de la base de datos en uso, que es el prefijo de los cerrojos.
     */
    private function database(): string
    {
        return (string) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getDatabase();
    }
}
