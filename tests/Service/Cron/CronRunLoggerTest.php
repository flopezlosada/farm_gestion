<?php

namespace App\Tests\Service\Cron;

use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use App\Service\Cron\CronRunLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El escritor del registro de ejecuciones.
 *
 * Escribe por DBAL con la tabla y las columnas literales (para que el SQL se
 * lea y para no arrastrar la unidad de trabajo del comando ni depender de que el
 * EntityManager siga abierto tras una excepción). El precio de esos literales es
 * que podrían quedar desfasados respecto al mapeo de la entidad sin que nada
 * cantase, así que aquí se vigilan uno a uno.
 */
class CronRunLoggerTest extends KernelTestCase
{
    /**
     * Limpia el registro que escriben estos tests.
     */
    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(CronRun::class)->findAll() as $run) {
            $em->remove($run);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * La tabla y las columnas que usa el SQL literal son las del mapeo de la
     * entidad. Si alguien renombra una columna en las anotaciones, este test
     * cae antes de que el registro empiece a fallar en silencio en producción.
     */
    public function testElSqlLiteralCoincideConElMapeoDeLaEntidad(): void
    {
        self::bootKernel();
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata(CronRun::class);

        $this->assertSame('cron_run', $metadata->getTableName());

        $expectedColumns = [
            'taskKey' => 'task_key',
            'command' => 'command',
            'status' => 'status',
            'triggerSource' => 'trigger_source',
            'startedAt' => 'started_at',
            'finishedAt' => 'finished_at',
            'exitCode' => 'exit_code',
            'detail' => 'detail',
            'output' => 'output',
        ];
        foreach ($expectedColumns as $field => $column) {
            $this->assertSame($column, $metadata->getColumnName($field), sprintf('La columna de "%s" ha cambiado de nombre.', $field));
        }
    }

    /**
     * Una ejecución se abre con estado `failed` y sin cierre: así, un proceso que
     * muere a mitad (timeout de php-fpm, kill) deja constancia del fallo en lugar
     * de no dejar rastro.
     */
    public function testLaEjecucionNaceComoFalloSinCerrar(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get(CronRunLogger::class);

        $runId = $logger->start('cron.test_logger', 'app:test-logger', CronRun::TRIGGER_SCHEDULE);
        $this->assertNotNull($runId);

        $run = self::getContainer()->get(CronRunRepository::class)->find($runId);
        $this->assertNotNull($run);
        $this->assertSame(CronRun::STATUS_FAILED, $run->getStatus());
        $this->assertFalse($run->isFinished(), 'Una ejecución recién abierta no tiene cierre.');
    }

    /**
     * Al cerrar se guardan estado, código de salida, resumen y salida.
     */
    public function testCerrarGuardaElResultado(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get(CronRunLogger::class);

        $runId = $logger->start('cron.test_logger', 'app:test-logger', CronRun::TRIGGER_MANUAL);
        $logger->finish($runId, CronRun::STATUS_DONE, 0, '3 cosas hechas', "linea 1\nlinea 2");

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $run = self::getContainer()->get(CronRunRepository::class)->find($runId);

        $this->assertSame(CronRun::STATUS_DONE, $run->getStatus());
        $this->assertSame(0, $run->getExitCode());
        $this->assertSame('3 cosas hechas', $run->getDetail());
        $this->assertStringContainsString('linea 2', (string) $run->getOutput());
        $this->assertTrue($run->isFinished());
    }

    /**
     * Una salida larguísima se recorta quedándose con el final (donde salen el
     * resumen del comando y la traza de un fallo) y se marca el recorte.
     */
    public function testLaSalidaLargaSeRecortaPorElPrincipio(): void
    {
        // El carácter del principio no puede aparecer en la marca de recorte
        // ("…(salida recortada)"), o la comprobación se engaña a sí misma.
        $run = (new CronRun())->setOutput(str_repeat('X', 100) . str_repeat('b', CronRun::OUTPUT_MAX_LENGTH));

        $output = (string) $run->getOutput();
        $this->assertStringContainsString('salida recortada', $output);
        $this->assertStringEndsWith('b', $output);
        $this->assertStringNotContainsString('X', $output, 'El principio es lo que se descarta.');
    }

    /**
     * Un resumen más largo que la columna se recorta al persistirlo: un mensaje
     * de excepción kilométrico no puede tumbar el registro del fallo que está
     * intentando dejar constancia.
     */
    public function testElResumenSeRecortaAlLargoDeLaColumna(): void
    {
        $run = (new CronRun())->setDetail(str_repeat('x', 400));

        $this->assertSame(255, mb_strlen((string) $run->getDetail()));
    }
}
