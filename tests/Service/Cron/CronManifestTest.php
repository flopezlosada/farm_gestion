<?php

namespace App\Tests\Service\Cron;

use App\Command\AbstractCronCommand;
use App\Service\AppSettings;
use App\Service\Cron\CronTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;

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
     * Toda tarea que manda correo (`confirm`, que es justo lo que ese campo
     * significa) exige el interruptor GENERAL de envíos además del suyo.
     *
     * Sin eso, con el interruptor general apagado la tarea corre entera,
     * {@see \App\Mailer\KillSwitchMailer} descarta los mensajes en silencio y
     * pasan dos cosas malas: la pantalla registra "hizo su trabajo" cuando no
     * entregó nada, y el guardián de idempotencia se queda con esos efectos
     * apuntados, así que al reencender el envío ya constan emitidos y no salen
     * nunca.
     */
    public function testLasTareasQueMandanCorreoExigenElInterruptorGeneral(): void
    {
        foreach (AppSettings::CRONS as $key => $meta) {
            if (!$meta['confirm'] || \in_array($key, self::MULTICANAL, true)) {
                continue;
            }

            $this->assertContains(
                AppSettings::EMAIL_ENABLED,
                $meta['requires'],
                sprintf('La tarea "%s" manda correo y no exige el interruptor general de envíos.', $key)
            );
        }
    }

    /**
     * Tareas que entregan por más de un canal, y que por eso NO pueden llevar en
     * `requires` el interruptor de uno solo: `requires` inhibe la tarea entera
     * (ni --force lo salta), así que apagar el correo dejaría también sin avisar
     * a quien lo tiene activado en el móvil.
     *
     * Entrar en esta lista NO exime de la regla de arriba, la muda de sitio: la
     * tarea tiene que leer el interruptor general ANTES de llamar al mailer, y
     * por tanto antes de que el guardián de idempotencia apunte nada. Es lo que
     * hace {@see \App\Command\SendPickupReminderCommand::doExecute()}, y el
     * motivo está en el javadoc del test de arriba: un correo descartado con el
     * apunte ya puesto no se manda nunca más.
     *
     * Si algún día hay tres o cuatro tareas aquí, deja de compensar la lista y
     * toca declarar los canales en el propio manifiesto.
     */
    private const MULTICANAL = [
        AppSettings::CRON_PICKUP_REMINDER,
    ];

    /**
     * La cadencia está bien formada: frecuencia conocida, hora válida y el campo
     * que corresponda a esa frecuencia (día de la semana en las semanales, día
     * del mes en las mensuales).
     */
    public function testLaCadenciaEstaBienFormada(): void
    {
        foreach (AppSettings::CRONS as $key => $meta) {
            $schedule = $meta['schedule'];

            $this->assertContains($schedule['freq'], ['daily', 'weekly', 'monthly', 'interval'], sprintf('Frecuencia desconocida en "%s".', $key));

            // Las de intervalo no tienen hora del día: corren cada N minutos. Es
            // lo que necesitan los avisos que se abren por pasos, donde el
            // segundo paso de algo que es pasado mañana llegaría tarde con una
            // cadencia diaria.
            if ($schedule['freq'] === 'interval') {
                $this->assertArrayHasKey('minutes', $schedule, sprintf('La tarea por intervalo "%s" no dice cada cuánto.', $key));
                $this->assertGreaterThan(0, $schedule['minutes'], sprintf('Intervalo no positivo en "%s".', $key));
                continue;
            }

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
            $schedule = $meta['schedule'];

            // En las de intervalo el mínimo sale del propio intervalo, no de una
            // tabla: una tarea cada 60 minutos con un plazo de una hora se
            // marcaría como caída en cuanto el reloj se retrase cinco minutos, y
            // los schedule de GitHub Actions se retrasan de serie.
            $minimum = $schedule['freq'] === 'interval'
                ? $schedule['minutes'] / 60
                : $minimumByFreq[$schedule['freq']];

            $this->assertGreaterThan(
                $minimum,
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
            $command = $this->unwrap($application->find($meta['command']));

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

            // Las que mandan correo (`confirm`) tienen que aceptar --resend: sus
            // efectos son idempotentes, y sin vía de repetición un aviso que no
            // llegó sólo se podría rescatar borrando su apunte a mano en la base
            // de datos. La pantalla ofrece el botón "Reenviar" a partir de ese
            // mismo `confirm`.
            if ($meta['confirm']) {
                $this->assertTrue($definition->hasOption('resend'), sprintf('El comando de "%s" manda correo y no acepta --resend.', $key));
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
        $declared = array_column(AppSettings::CRONS, 'command');
        $checked = 0;

        // Por reflexión y no preguntando a la aplicación: `all()` devuelve los
        // comandos envueltos en LazyCommand, y desenvolverlos instanciaría de
        // golpe todos los comandos del proyecto sólo para mirar de qué heredan.
        foreach (glob(\dirname(__DIR__, 3) . '/src/Command/*.php') as $file) {
            $class = 'App\\Command\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(AbstractCronCommand::class)) {
                continue;
            }

            $attribute = $reflection->getAttributes(AsCommand::class)[0] ?? null;
            $this->assertNotNull($attribute, sprintf('%s no declara #[AsCommand].', $class));

            ++$checked;
            $this->assertContains(
                $attribute->newInstance()->name,
                $declared,
                sprintf('%s hereda de AbstractCronCommand pero no está en AppSettings::CRONS.', $class)
            );
        }

        $this->assertSame(count(AppSettings::CRONS), $checked, 'El número de comandos de cron y de entradas del manifiesto debe coincidir.');
    }

    /**
     * El comando real detrás de un LazyCommand.
     *
     * Symfony registra envueltos en LazyCommand los comandos que declaran
     * descripción en #[AsCommand] (AddConsoleCommandPass), para no instanciarlos
     * al arrancar. Sin desenvolver, cualquier comprobación de tipo sobre la
     * clase del comando miente.
     *
     * @param Command $command Comando tal y como lo devuelve la aplicación.
     */
    private function unwrap(Command $command): Command
    {
        return $command instanceof LazyCommand ? $command->getCommand() : $command;
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
