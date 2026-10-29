<?php

namespace App\Tests\Service\Cron;

use App\Entity\CronRun;
use App\Service\AppSettings;
use App\Service\Cron\Adapter\AppSettingsCronManifest;
use App\Service\Cron\CronSchedule;
use PHPUnit\Framework\TestCase;

/**
 * La hora del listado y de la confirmación sale del ajuste editable, no de la
 * constante del manifiesto.
 *
 * Administración mete por la mañana los cambios que lxs socixs avisan antes del
 * cierre; si las dos tareas salieran a su hora fija, el listado y la confirmación
 * no los llevarían. Lo que se vigila es que el tick (isDue) y la pantalla
 * (describe) lean la MISMA hora, la del ajuste, y que el resto de tareas no se
 * enteren.
 */
class AppSettingsCronManifestTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function tareasDelListado(): array
    {
        return [
            'listado al equipo' => [AppSettings::CRON_DELIVERY_SHEET],
            'confirmación al socix' => [AppSettings::CRON_DELIVERY_CONFIRMATION],
            'recordatorio de recogida' => [AppSettings::CRON_PICKUP_REMINDER],
        ];
    }

    /**
     * El recordatorio corre DETRÁS de la confirmación, y la confirmación detrás
     * del listado. En una misma pasada las tareas corren en el orden del
     * manifiesto: con el plazo cerrado el recordatorio ya no avisa, sólo comprueba
     * que nadie se quedó sin aviso, y si corriera antes que la confirmación
     * acusaría de hueco a todo el que todavía no la ha recibido.
     */
    public function testElRecordatorioCorreDetrasDeLaConfirmacion(): void
    {
        $order = array_flip(array_keys(AppSettings::CRONS));

        $this->assertLessThan($order[AppSettings::CRON_DELIVERY_CONFIRMATION], $order[AppSettings::CRON_DELIVERY_SHEET]);
        $this->assertLessThan($order[AppSettings::CRON_PICKUP_REMINDER], $order[AppSettings::CRON_DELIVERY_CONFIRMATION]);
    }

    /**
     * @dataProvider tareasDelListado
     *
     * @param string $taskKey Clave de la tarea en el manifiesto.
     */
    public function testLaHoraSaleDelAjuste(string $taskKey): void
    {
        $manifest = new AppSettingsCronManifest($this->settingsWithSendTime('14:30'));
        $schedule = new CronSchedule($manifest);

        $meta = $manifest->tasks()[$taskKey];

        $this->assertSame(14, $meta['schedule']['hour']);
        $this->assertSame(30, $meta['schedule']['minute']);
        $this->assertSame('daily', $meta['schedule']['freq'], 'La frecuencia sigue siendo la del manifiesto.');
        $this->assertSame('a diario a las 14:30', $schedule->describe($meta['schedule']));

        // Ya corrió ayer a su hora: hoy a las 14:29 aún no le toca; a las 14:30, sí.
        $lastRun = (new CronRun())
            ->setStartedAt($this->moment('2026-10-28 14:30'))
            ->setStatus(CronRun::STATUS_DONE);
        $this->assertFalse($schedule->isDue($meta['schedule'], $lastRun, $this->moment('2026-10-29 14:29')));
        $this->assertTrue($schedule->isDue($meta['schedule'], $lastRun, $this->moment('2026-10-29 14:30')));
    }

    /**
     * Las tareas sin `hour_setting` conservan su cadencia tal cual.
     */
    public function testElRestoDeTareasNoCambia(): void
    {
        $tasks = (new AppSettingsCronManifest($this->settingsWithSendTime('14:30')))->tasks();

        foreach (AppSettings::CRONS as $key => $meta) {
            if (!isset($meta['hour_setting'])) {
                $this->assertSame($meta, $tasks[$key], sprintf('La tarea "%s" no declara hora editable y no debe cambiar.', $key));
            }
        }
    }

    /**
     * Un instante en hora peninsular, que es en la que están escritos los casos.
     *
     * @param string $when Momento "Y-m-d H:i".
     */
    private function moment(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('Europe/Madrid'));
    }

    /**
     * AppSettings mockeado que devuelve la hora de envío dada.
     *
     * @param string $time Hora "HH:MM".
     */
    private function settingsWithSendTime(string $time): AppSettings
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getTime')->willReturnMap([[AppSettings::DELIVERY_SHEET_SEND_TIME, $time]]);

        return $settings;
    }
}
