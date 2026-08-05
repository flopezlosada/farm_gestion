<?php

namespace App\Tests\Service\Cron;

use App\Command\AbstractCronCommand;
use App\Service\AppSettings;
use App\Service\Cron\CronTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Coherencia del manifiesto de tareas programadas ({@see AppSettings::CRONS}).
 *
 * El manifiesto es ahora la fuente única de la que salen el gate, la cadencia y
 * los avisos, así que una entrada mal declarada no da un error visible: da una
 * tarea que no se inhibe, o que nadie vigila. Estos tests son el guardián de esa
 * fuente, y por eso comprueban también la relación INVERSA (que ningún comando
 * de cron se quede fuera del manifiesto).
 */
class CronManifestTest extends KernelTestCase
{
    /**
     * Cada tarea tiene su interruptor en el catálogo de booleanos: sin él no
     * habría ni gate ni etiqueta que mostrar.
     */
    public function testCadaTareaTieneSuInterruptorEnElCatalogo(): void
    {
        foreach (array_keys(AppSettings::CRONS) as $key) {
            $this->assertArrayHasKey(
                $key,
                AppSettings::BOOLEANS,
                sprintf('La tarea "%s" no tiene interruptor declarado en AppSettings::BOOLEANS.', $key)
            );
        }
    }

    /**
     * Las claves referenciadas en `requires` y `depends_on` existen: una
     * referencia rota haría que AppSettings::getBool() reventara en plena
     * ejecución del cron.
     */
    public function testLasReferenciasDelManifiestoExisten(): void
    {
        foreach (AppSettings::CRONS as $key => $meta) {
            foreach ($meta['requires'] as $required) {
                $this->assertArrayHasKey(
                    $required,
                    AppSettings::BOOLEANS,
                    sprintf('La tarea "%s" exige el ajuste inexistente "%s".', $key, $required)
                );
            }
            foreach ($meta['depends_on'] as $dependency) {
                $this->assertArrayHasKey(
                    $dependency,
                    AppSettings::CRONS,
                    sprintf('La tarea "%s" depende de la tarea inexistente "%s".', $key, $dependency)
                );
            }
        }
    }

    /**
     * La cadencia está bien formada: frecuencia conocida, hora válida y el campo
     * que corresponda a esa frecuencia (día de la semana en las semanales, día
     * del mes en las mensuales).
     */
    public function testLaCadenciaEstaBienFormada(): void
    {
        foreach (AppSettings::CRONS as $key => $meta) {
            $schedule = $meta['schedule'];

            $this->assertContains($schedule['freq'], ['daily', 'weekly', 'monthly'], sprintf('Frecuencia desconocida en "%s".', $key));
            $this->assertGreaterThanOrEqual(0, $schedule['hour'], sprintf('Hora fuera de rango en "%s".', $key));
            $this->assertLessThanOrEqual(23, $schedule['hour'], sprintf('Hora fuera de rango en "%s".', $key));

            if ($schedule['freq'] === 'weekly') {
                $this->assertArrayHasKey('dow', $schedule, sprintf('La tarea semanal "%s" no dice qué día.', $key));
                $this->assertGreaterThanOrEqual(1, $schedule['dow']);
                $this->assertLessThanOrEqual(7, $schedule['dow']);
            }
            if ($schedule['freq'] === 'monthly') {
                $this->assertArrayHasKey('dom', $schedule, sprintf('La tarea mensual "%s" no dice qué día del mes.', $key));
                $this->assertGreaterThanOrEqual(1, $schedule['dom']);
                $this->assertLessThanOrEqual(28, $schedule['dom'], 'Un día > 28 no existe todos los meses.');
            }
        }
    }

    /**
     * El plazo máximo de retraso da margen sobre la cadencia. Un plazo más corto
     * que el propio período entre ejecuciones marcaría como caída una tarea
     * perfectamente sana, y a la tercera falsa alarma nadie vuelve a mirar la
     * pantalla.
     */
    public function testElPlazoDeRetrasoDaMargenSobreLaCadencia(): void
    {
        $minimumByFreq = ['daily' => 24, 'weekly' => 168, 'monthly' => 744];

        foreach (AppSettings::CRONS as $key => $meta) {
            $this->assertGreaterThan(
                $minimumByFreq[$meta['schedule']['freq']],
                $meta['max_delay_hours'],
                sprintf('El plazo de "%s" es más corto que su propia cadencia: daría falsas alarmas.', $key)
            );
        }
    }

    /**
     * Todos los comandos del manifiesto existen, heredan de la base (que es
     * quien aplica el gate y registra) y declaran las dos opciones del contrato:
     * --force para la ejecución manual y --dry-run para previsualizar.
     */
    public function testLosComandosExistenHeredanDeLaBaseYAceptanElContrato(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        foreach (AppSettings::CRONS as $key => $meta) {
            $command = $application->find($meta['command']);

            $this->assertInstanceOf(
                AbstractCronCommand::class,
                $command,
                sprintf('El comando de "%s" no hereda de AbstractCronCommand: se saltaría el gate y el registro.', $key)
            );

            $definition = $command->getDefinition();
            $this->assertTrue($definition->hasOption('force'), sprintf('El comando de "%s" no acepta --force.', $key));
            $this->assertTrue($definition->hasOption('dry-run'), sprintf('El comando de "%s" no acepta --dry-run.', $key));

            // Si el manifiesto dice que necesita destinatario, el comando tiene
            // que poder recibirlo: si no, el botón de ejecución manual fallaría.
            if ($meta['needs_recipient']) {
                $this->assertTrue($definition->hasOption('to'), sprintf('El comando de "%s" dice necesitar destinatario pero no acepta --to.', $key));
            }
        }
    }

    /**
     * La relación inversa: ningún comando que herede de la base se queda fuera
     * del manifiesto. Uno fuera sería una tarea sin cadencia declarada, sin
     * plazo y sin vigilancia — exactamente el agujero que este trabajo cierra.
     */
    public function testNingunComandoDeCronSeQuedaFueraDelManifiesto(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $declared = array_column(AppSettings::CRONS, 'command');

        foreach ($application->all() as $name => $command) {
            if (!$command instanceof AbstractCronCommand) {
                continue;
            }
            $this->assertContains(
                $name,
                $declared,
                sprintf('El comando "%s" hereda de AbstractCronCommand pero no está en AppSettings::CRONS.', $name)
            );
        }
    }

    /**
     * La cadencia se describe en castellano legible, que es lo que la pantalla
     * pinta junto a cada tarea.
     */
    public function testLaCadenciaSeDescribeEnCastellano(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(CronTaskRegistry::class);

        $this->assertSame('los lunes a las 06:00', $registry->describeSchedule(AppSettings::CRON_GENERATE_WEEKLY_DELIVERY));
        $this->assertSame('a diario a las 09:00', $registry->describeSchedule(AppSettings::CRON_PICKUP_REMINDER));
        $this->assertSame('el día 1 de cada mes a las 04:00', $registry->describeSchedule(AppSettings::CRON_PURGE_USAGE_HITS));
    }
}
