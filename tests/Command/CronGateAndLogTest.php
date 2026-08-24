<?php

namespace App\Tests\Command;

use App\Entity\CronRun;
use App\Entity\Setting;
use App\Entity\UsageHit;
use App\Repository\CronRunRepository;
use App\Repository\UsageHitRepository;
use App\Service\AppSettings;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * El gate de interruptores y el registro de ejecuciones, que viven en
 * {@see \App\Command\AbstractCronCommand} y son comunes a todas las tareas.
 *
 * Se ejercitan sobre `app:purge-usage-hits`, que es la única tarea que no manda
 * correo y es idempotente, así que se puede correr de verdad sin efectos
 * incómodos. Para el gate que NO se puede saltar (los interruptores de entrega)
 * se usa el recordatorio del albergue.
 *
 * Los tests van por CommandTester, o sea por el mismo camino que el cron del
 * hosting: es ahí donde el gate tiene que aplicarse, no sólo en la web.
 */
class CronGateAndLogTest extends KernelTestCase
{
    private const PURGE = 'app:purge-usage-hits';

    /**
     * Retención absurdamente larga: garantiza que no hay nada más antiguo que
     * borrar, así el resultado "no había trabajo" no depende de qué haya dejado
     * en la tabla otro test.
     */
    private const NOTHING_OLD_ENOUGH = ['--days' => '36500'];

    /**
     * Deja limpio lo que estos tests escriben: el registro de ejecuciones y los
     * overrides de configuración (sin filas en `setting` vuelven los defaults
     * del catálogo).
     */
    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(CronRun::class)->findAll() as $run) {
            $em->remove($run);
        }
        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * Con la tarea encendida y sin trabajo pendiente: corre, sale en verde y
     * queda registrada como `nothing_to_do` — el estado sano que hasta ahora era
     * indistinguible de "apagada".
     */
    public function testSinTrabajoRegistraNothingToDo(): void
    {
        self::bootKernel();

        $exit = $this->tester()->execute(self::NOTHING_OLD_ENOUGH);

        $this->assertSame(Command::SUCCESS, $exit);
        $run = $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS);
        $this->assertNotNull($run, 'La ejecución por consola debe quedar registrada.');
        $this->assertSame(CronRun::STATUS_NOTHING_TO_DO, $run->getStatus());
        $this->assertSame(CronRun::TRIGGER_SCHEDULE, $run->getTriggerSource(), 'Por consola el origen es el reloj: sólo el runner de la web marca "a mano".');
        $this->assertTrue($run->isFinished());
    }

    /**
     * Con la tarea apagada en /gestion/settings: no se ejecuta, sale en verde
     * (para no disparar alertas del cron) y queda registrada como `disabled`.
     */
    public function testApagadaNoEjecutaYRegistraDisabled(): void
    {
        self::bootKernel();
        $this->settings()->setBool(AppSettings::CRON_PURGE_USAGE_HITS, false);

        $tester = $this->tester();
        $exit = $tester->execute(self::NOTHING_OLD_ENOUGH);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('desactivada', $tester->getDisplay(), 'El comando debe seguir diciendo algo al arrancar: es lo que permite datar una caída leyendo el log.');

        $run = $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS);
        $this->assertNotNull($run);
        $this->assertSame(CronRun::STATUS_DISABLED, $run->getStatus());
        $this->assertStringContainsString('desactivada', (string) $run->getDetail());
    }

    /**
     * `--force` salta el interruptor propio de la tarea: es lo que permite
     * congelar el listado a mano con el cron caído.
     *
     * Lo que NO hace es decidir quién lanzó la ejecución. Ese es un eje distinto
     * ({@see \App\Command\AbstractCronCommand::markLaunchedByHand()}, que llama el
     * runner de la web), porque la pantalla de diagnóstico lanza a mano SIN
     * forzar; si se dedujera de --force, esas ejecuciones se harían pasar por el
     * reloj.
     */
    public function testForceSaltaElInterruptorPeroNoDecideElOrigen(): void
    {
        self::bootKernel();
        $this->settings()->setBool(AppSettings::CRON_PURGE_USAGE_HITS, false);

        $exit = $this->tester()->execute(self::NOTHING_OLD_ENOUGH + ['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $run = $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS);
        $this->assertNotNull($run);
        $this->assertNotSame(CronRun::STATUS_DISABLED, $run->getStatus(), '--force debe saltar el interruptor de la tarea.');
        $this->assertSame(CronRun::TRIGGER_SCHEDULE, $run->getTriggerSource(), 'Por consola, aunque fuerce, el origen sigue siendo el reloj.');
    }

    /**
     * `--force` NO salta los interruptores de ENTREGA declarados en `requires`:
     * con el envío de emails del albergue apagado, ni el cron ni una ejecución
     * manual mandan nada. Es el contrato que ya estaba vigente y que no debe
     * cambiar al mover el gate.
     */
    public function testForceNoSaltaElInterruptorDeEnvio(): void
    {
        self::bootKernel();
        // El interruptor de la tarea viene encendido por defecto y el del email
        // apagado: la ejecución se para en el segundo, incluso con --force.
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:send-albergue-arrivals-reminder')
        );
        $exit = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('desactivado', $tester->getDisplay());

        $run = $this->lastRun(AppSettings::CRON_ALBERGUE_REMINDER);
        $this->assertNotNull($run);
        $this->assertSame(CronRun::STATUS_DISABLED, $run->getStatus());
    }

    /**
     * Una previsualización no es una ejecución: `--dry-run` no pasa por el gate
     * (para poder ver qué haría con la tarea apagada) y NO se registra, para no
     * falsear la última ejecución de la pantalla.
     */
    public function testDryRunNoSeRegistra(): void
    {
        self::bootKernel();
        $this->settings()->setBool(AppSettings::CRON_PURGE_USAGE_HITS, false);

        $exit = $this->tester()->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertNull(
            $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS),
            'Una previsualización no debe contar como ejecución.'
        );
    }

    /**
     * Cuando hay trabajo de verdad, el estado es `done` y la salida del comando
     * queda guardada: en un hosting sin SSH, verla aquí ahorra bajarse
     * var/log/cron.log por FTP.
     */
    public function testConTrabajoRegistraDoneYGuardaLaSalida(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $old = new UsageHit('gestion', 'test_cron_run', 'GET', 200, null, new \DateTimeImmutable('-400 days'));
        $em->persist($old);
        $em->flush();

        $exit = $this->tester()->execute(['--days' => '365']);

        $this->assertSame(Command::SUCCESS, $exit);
        $run = $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS);
        $this->assertNotNull($run);
        $this->assertSame(CronRun::STATUS_DONE, $run->getStatus());
        $this->assertStringContainsString('usage_hit', (string) $run->getOutput(), 'La salida del comando debe quedar registrada.');
    }

    /**
     * Un comando que termina con código de error queda registrado como `failed`.
     * `--days=0` es inválido y el comando lo rechaza, así que sirve de fallo
     * reproducible sin tener que provocar una excepción.
     */
    public function testUnFalloSeRegistraComoFailed(): void
    {
        self::bootKernel();

        $exit = $this->tester()->execute(['--days' => '0']);

        $this->assertNotSame(Command::SUCCESS, $exit);
        $run = $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS);
        $this->assertNotNull($run);
        $this->assertSame(CronRun::STATUS_FAILED, $run->getStatus());
        $this->assertSame($exit, $run->getExitCode());
    }

    /**
     * Una excepción a mitad de la tarea queda registrada como `failed` y NO se
     * la traga el registro: sigue su curso. Es el camino que más importa
     * registrar bien (un fallo que no se anota es peor que no tener registro) y
     * el único que no se puede provocar con datos, así que se sustituye el
     * repositorio por uno que revienta.
     */
    public function testUnaExcepcionSeRegistraComoFailedYSeRelanza(): void
    {
        self::bootKernel();

        $exploding = $this->createMock(UsageHitRepository::class);
        $exploding->method('deleteOlderThan')->willThrowException(new \RuntimeException('boom de prueba'));
        self::getContainer()->set(UsageHitRepository::class, $exploding);

        $thrown = null;
        try {
            $this->tester()->execute(self::NOTHING_OLD_ENOUGH);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'La excepción debe seguir su curso, no quedarse en el registro.');

        $run = $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS);
        $this->assertNotNull($run);
        $this->assertSame(CronRun::STATUS_FAILED, $run->getStatus());
        $this->assertStringContainsString('boom de prueba', (string) $run->getDetail());
        $this->assertTrue($run->isFinished(), 'Un fallo registrado sí tiene cierre; sin cierre significa que el proceso murió.');
    }

    /**
     * Si la misma tarea ya está corriendo en otro proceso, esta pasada se retira:
     * sale en verde (no es una avería, es el sistema funcionando) y NO registra
     * una ejecución, porque no ha habido ninguna — la que la pantalla debe seguir
     * mostrando es la que está en marcha.
     *
     * El "otro proceso" se simula con una segunda conexión, porque los bloqueos
     * con nombre de MySQL son reentrantes dentro de la misma.
     */
    public function testSiLaTareaYaEstaCorriendoLaPasadaSeRetira(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $otherProcess = DriverManager::getConnection($em->getConnection()->getParams());
        $lockName = $em->getConnection()->getDatabase() . ':' . AppSettings::CRON_PURGE_USAGE_HITS;

        try {
            $this->assertSame(1, (int) $otherProcess->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]));

            $tester = $this->tester();
            $exit = $tester->execute(self::NOTHING_OLD_ENOUGH);

            $this->assertSame(Command::SUCCESS, $exit, 'Retirarse no es fallar: un error haría que el reloj avisara de una avería inexistente.');
            $this->assertStringContainsString('ya está ejecutándose', $tester->getDisplay());
            $this->assertNull(
                $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS),
                'Una pasada que se retira no es una ejecución y no debe registrarse.'
            );
        } finally {
            $otherProcess->close();
        }
    }

    /**
     * CommandTester de la purga (el kernel ya debe estar arrancado).
     */
    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find(self::PURGE));
    }

    /**
     * Última ejecución registrada de una tarea, o null si no hay ninguna.
     */
    private function lastRun(string $taskKey): ?CronRun
    {
        $repository = self::getContainer()->get(CronRunRepository::class);

        return $repository->findRecentForTask($taskKey, 1)[0] ?? null;
    }

    /**
     * Servicio de configuración del contenedor de test.
     */
    private function settings(): AppSettings
    {
        return self::getContainer()->get(AppSettings::class);
    }
}
