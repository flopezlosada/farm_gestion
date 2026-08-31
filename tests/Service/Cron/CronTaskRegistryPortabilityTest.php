<?php

namespace App\Tests\Service\Cron;

use App\Entity\CronRun;
use App\Service\Cron\CronManifest;
use App\Service\Cron\CronSchedule;
use App\Service\Cron\CronTaskRegistry;
use PHPUnit\Framework\TestCase;

/**
 * El planificador funciona con un manifiesto que NO es el de este proyecto.
 *
 * Es la prueba de que la costura está donde decimos: aquí no hay kernel, ni base
 * de datos, ni `AppSettings`, ni una sola tarea de la CSA. Sólo un manifiesto
 * inventado de dos tareas. Si algún día alguien vuelve a meter una dependencia
 * del proyecto dentro de {@see CronTaskRegistry}, este test deja de compilar o
 * de pasar, y el trasplante a gestión centro o SGA se entera aquí y no allí.
 *
 * Los demás tests del planificador (gate, registro, cerrojo, idempotencia) van
 * contra el manifiesto real, que es lo que hay que vigilar en producción; éste
 * vigila la portabilidad.
 */
class CronTaskRegistryPortabilityTest extends TestCase
{
    /**
     * Un manifiesto ajeno se lee igual: cadencias, gate y dependencias salen de
     * lo que declare, sin que el núcleo sepa de dónde viene.
     */
    public function testFuncionaConUnManifiestoAjeno(): void
    {
        $registry = $this->registry();

        $this->assertSame(['tarea.limpieza', 'tarea.aviso'], array_keys($registry->all()));
        $this->assertSame('los miércoles a las 03:30', $registry->describeSchedule('tarea.limpieza'));
        $this->assertSame('a diario a las 07:00', $registry->describeSchedule('tarea.aviso'));
        $this->assertSame('Limpieza nocturna', $registry->label('tarea.limpieza'));
        $this->assertNull($registry->get('tarea.que.no.existe'));
    }

    /**
     * El gate distingue los dos tipos de interruptor con un manifiesto
     * cualquiera: el propio de la tarea lo salta una ejecución forzada, el de
     * entrega no.
     */
    public function testElGateSeComportaIgualConCualquierManifiesto(): void
    {
        $registry = $this->registry();

        // "tarea.aviso" está apagada y además exige un ajuste de entrega apagado.
        $this->assertStringContainsString('desactivada', (string) $registry->inhibitedReason('tarea.aviso'));
        $this->assertStringContainsString(
            'no entrega',
            (string) $registry->inhibitedReason('tarea.aviso', force: true),
            'Forzar salta el interruptor de la tarea, nunca el de entrega.'
        );

        // "tarea.limpieza" está encendida y no exige nada más.
        $this->assertNull($registry->inhibitedReason('tarea.limpieza'));
    }

    /**
     * El plazo de retraso se mide contra la última ejecución y sólo para las
     * tareas encendidas: una apagada a propósito no está caída.
     */
    public function testElRetrasoSoloSeMideEnLasTareasEncendidas(): void
    {
        $registry = $this->registry();
        $ahora = new \DateTimeImmutable('2099-03-04 12:00:00');
        $haceCuatroDias = (new CronRun())->setStartedAt(new \DateTimeImmutable('2099-02-28 12:00:00'));

        $this->assertTrue($registry->isOverdue('tarea.limpieza', $haceCuatroDias, $ahora), 'Encendida y pasada de plazo.');
        $this->assertFalse($registry->isOverdue('tarea.aviso', $haceCuatroDias, $ahora), 'Apagada: no está caída, está apagada.');
        $this->assertFalse($registry->isOverdue('tarea.limpieza', null, $ahora), 'Sin ninguna ejecución no hay desde dónde medir.');
    }

    /**
     * El registro montado a mano sobre el manifiesto inventado, con su lector de
     * cadencias. Sin contenedor: es parte de lo que se demuestra.
     */
    private function registry(): CronTaskRegistry
    {
        $manifest = $this->manifest();

        return new CronTaskRegistry($manifest, new CronSchedule($manifest));
    }

    /**
     * Manifiesto inventado, de un proyecto que no existe: una tarea semanal
     * encendida y una diaria apagada que además exige un ajuste de entrega.
     * Declara su propia zona horaria, que en este caso ni siquiera es la de la
     * CSA — el planificador no tiene ninguna metida dentro.
     */
    private function manifest(): CronManifest
    {
        return new class implements CronManifest {
            /** @var array<string, bool> Interruptores de este manifiesto de mentira. */
            private array $switches = [
                'tarea.limpieza' => true,
                'tarea.aviso' => false,
                'entrega.mensajes' => false,
            ];

            public function tasks(): array
            {
                return [
                    'tarea.limpieza' => [
                        'command' => 'demo:limpieza',
                        'schedule' => ['freq' => 'weekly', 'dow' => 3, 'hour' => 3, 'minute' => 30],
                        'max_delay_hours' => 72,
                        'requires' => [],
                        'depends_on' => [],
                    ],
                    'tarea.aviso' => [
                        'command' => 'demo:aviso',
                        'schedule' => ['freq' => 'daily', 'hour' => 7],
                        'max_delay_hours' => 36,
                        'requires' => ['entrega.mensajes'],
                        'depends_on' => ['tarea.limpieza'],
                    ],
                ];
            }

            public function isEnabled(string $settingKey): bool
            {
                return $this->switches[$settingKey] ?? false;
            }

            public function label(string $settingKey): string
            {
                return match ($settingKey) {
                    'tarea.limpieza' => 'Limpieza nocturna',
                    'tarea.aviso' => 'Aviso diario',
                    'entrega.mensajes' => 'Envío de mensajes',
                    default => $settingKey,
                };
            }

            public function timezone(): string
            {
                return 'Atlantic/Canary';
            }
        };
    }
}
