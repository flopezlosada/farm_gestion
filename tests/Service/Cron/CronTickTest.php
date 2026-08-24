<?php

namespace App\Tests\Service\Cron;

use App\Entity\CronRun;
use App\Entity\Setting;
use App\Service\AppSettings;
use App\Service\Cron\CronTick;
use App\Repository\CronRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El latido: qué elige ejecutar en cada pasada.
 *
 * Se ejercita con TODAS las tareas apagadas menos la purga de rastro de uso, que
 * es la única inocua (no manda correo y borra filas viejas que en `db_test` no
 * existen). Así el tick corre de verdad, de punta a punta, sin efectos molestos.
 */
class CronTickTest extends KernelTestCase
{
    /**
     * Deja el registro de ejecuciones y la configuración como estaban: sin filas
     * en `setting` vuelven los valores por defecto del catálogo.
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
     * Una tarea encendida que nunca ha corrido se ejecuta en el primer tick, y
     * queda registrada como disparada por EL RELOJ, no a mano. Esa distinción es
     * la que permite que la pantalla no dé por vivo un planificador parado.
     */
    public function testEjecutaLoQueTocaYLoRegistraComoDelReloj(): void
    {
        self::bootKernel();
        $this->onlyEnabled(AppSettings::CRON_PURGE_USAGE_HITS);

        $done = self::getContainer()->get(CronTick::class)->run();

        $this->assertArrayHasKey(AppSettings::CRON_PURGE_USAGE_HITS, $done);

        $run = $this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS);
        $this->assertNotNull($run, 'La ejecución del tick debe quedar registrada.');
        $this->assertSame(CronRun::TRIGGER_SCHEDULE, $run->getTriggerSource());
    }

    /**
     * El segundo tick de la misma hora no repite nada. Es lo que hace que un
     * reloj que llame cada hora —o dos relojes en paralelo— no disparen la misma
     * tarea una y otra vez.
     */
    public function testUnSegundoTickNoRepiteElTrabajo(): void
    {
        self::bootKernel();
        $this->onlyEnabled(AppSettings::CRON_PURGE_USAGE_HITS);
        $tick = self::getContainer()->get(CronTick::class);

        $tick->run();
        $segundo = $tick->run();

        $this->assertSame([], $segundo, 'Ya corrió en esta ocurrencia: no toca otra vez.');
    }

    /**
     * Las tareas apagadas ni se miran. Con el tick pasando cada hora, dejarlas
     * llegar al gate del comando llenaría el registro de filas "apagada" y la
     * pantalla dejaría de decir nada útil.
     */
    public function testNoTocaLasTareasApagadas(): void
    {
        self::bootKernel();
        $this->onlyEnabled(null);

        $this->assertSame([], self::getContainer()->get(CronTick::class)->run());
        $this->assertNull($this->lastRun(AppSettings::CRON_PURGE_USAGE_HITS), 'Ni siquiera debe registrarse.');
    }

    /**
     * Deja encendida SÓLO la tarea indicada (o ninguna, con null).
     *
     * @param string|null $key Clave de la tarea a dejar encendida.
     */
    private function onlyEnabled(?string $key): void
    {
        $settings = self::getContainer()->get(AppSettings::class);
        foreach (array_keys(AppSettings::CRONS) as $task) {
            $settings->setBool($task, $task === $key);
        }
    }

    /**
     * Última ejecución registrada de una tarea, o null si no hay.
     *
     * @param string $taskKey Clave de la tarea.
     */
    private function lastRun(string $taskKey): ?CronRun
    {
        return self::getContainer()->get(CronRunRepository::class)->findRecentForTask($taskKey, 1)[0] ?? null;
    }
}
