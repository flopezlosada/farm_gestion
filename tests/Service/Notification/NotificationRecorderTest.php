<?php

namespace App\Tests\Service\Notification;

use App\Entity\NotificationLog;
use App\Repository\NotificationLogRepository;
use App\Service\Cron\CronRunContext;
use App\Service\Notification\NotificationRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El escritor de la bitácora de avisos.
 *
 * Escribe por DBAL con tabla y columnas literales, por las mismas razones que
 * {@see \App\Service\Cron\CronRunLogger} y una más: estos apuntes ocurren en
 * mitad de la unidad de trabajo de quien está enviando. El precio de los
 * literales es que pueden desfasarse del mapeo sin que nada cante, así que se
 * vigilan uno a uno.
 */
class NotificationRecorderTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(NotificationLog::class)->findBy(['kind' => 'test_recorder']) as $log) {
            $em->remove($log);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * La tabla y las columnas del SQL literal son las del mapeo. Si alguien
     * renombra una columna en las anotaciones, esto cae antes de que la
     * bitácora empiece a perder avisos en silencio.
     */
    public function testElSqlLiteralCoincideConElMapeoDeLaEntidad(): void
    {
        self::bootKernel();
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata(NotificationLog::class);

        $this->assertSame('notification_log', $metadata->getTableName());

        $expected = [
            'sentAt' => 'sent_at',
            'channel' => 'channel',
            'kind' => 'kind',
            'subject' => 'subject',
            'target' => 'target',
            'status' => 'status',
            'error' => 'error',
            'taskKey' => 'task_key',
        ];
        foreach ($expected as $field => $column) {
            $this->assertSame($column, $metadata->getColumnName($field), sprintf('La columna de "%s" ha cambiado de nombre.', $field));
        }

        // Las dos claves ajenas se escriben también por su nombre de columna.
        $this->assertSame('partner_id', $metadata->getSingleAssociationJoinColumnName('partner'));
        $this->assertSame('cron_run_id', $metadata->getSingleAssociationJoinColumnName('cronRun'));
    }

    /** Un envío correcto deja su línea, con canal, tipo, destino y asunto. */
    public function testApuntaUnEnvioCorrecto(): void
    {
        self::bootKernel();
        $recorder = self::getContainer()->get(NotificationRecorder::class);

        $recorder->sent(NotificationLog::CHANNEL_EMAIL, 'test_recorder', 'quien@test.org', 'Un asunto');

        $log = $this->lastLog();
        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_SENT, $log->getStatus());
        $this->assertSame(NotificationLog::CHANNEL_EMAIL, $log->getChannel());
        $this->assertSame('quien@test.org', $log->getTarget());
        $this->assertSame('Un asunto', $log->getSubject());
        $this->assertNull($log->getTaskKey(), 'Fuera de una tarea programada, no hay tarea que apuntar.');
    }

    /**
     * Un fallo guarda el motivo recortado a lo que cabe: los mensajes de
     * excepción de un transporte de correo traen a veces la traza entera, y el
     * registro del fallo no puede reventar por intentar guardarlo.
     */
    public function testUnFalloGuardaElMotivoRecortado(): void
    {
        self::bootKernel();
        $recorder = self::getContainer()->get(NotificationRecorder::class);

        $recorder->failed(NotificationLog::CHANNEL_PUSH, 'test_recorder', '2 navegador(es)', str_repeat('x', 900));

        $log = $this->lastLog();
        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->getStatus());
        $this->assertSame(NotificationLog::ERROR_MAX_LENGTH, mb_strlen((string) $log->getError()));
    }

    /**
     * Dentro de una tarea programada, el apunte sabe de qué tarea salió. Es lo
     * que permite ir de una ejecución a los avisos que produjo.
     */
    public function testDentroDeUnaTareaApuntaCualEra(): void
    {
        self::bootKernel();
        $context = self::getContainer()->get(CronRunContext::class);
        $recorder = self::getContainer()->get(NotificationRecorder::class);

        $context->enter('cron.pickup_reminder', null);
        try {
            $recorder->sent(NotificationLog::CHANNEL_EMAIL, 'test_recorder', 'quien@test.org', 'Con tarea');
        } finally {
            $context->leave();
        }

        $this->assertSame('cron.pickup_reminder', $this->lastLog()?->getTaskKey());
    }

    private function lastLog(): ?NotificationLog
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(NotificationLogRepository::class)
            ->findOneBy(['kind' => 'test_recorder'], ['id' => 'DESC']);
    }
}
