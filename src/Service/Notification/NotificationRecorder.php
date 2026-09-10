<?php

namespace App\Service\Notification;

use App\Entity\NotificationLog;
use App\Entity\Partner;
use App\Service\Cron\CronRunContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Apunta en la bitácora ({@see NotificationLog}) cada aviso que se intenta
 * entregar. Lo llaman los dos transportes —correo y push—, no los emisores.
 *
 * ESCRIBE POR DBAL, NO POR EL ENTITYMANAGER, por el mismo motivo que
 * {@see \App\Service\Cron\CronRunLogger}, y aquí aprieta todavía más: estos
 * apuntes ocurren DENTRO del envío, o sea en mitad de la unidad de trabajo de
 * quien está mandando. Un `flush()` aquí confirmaría a medias los cambios de la
 * tarea —el {@see \App\Service\Push\PushSender} tiene suscripciones muertas
 * marcadas para borrar, por ejemplo— en un punto que nadie eligió.
 *
 * NINGÚN FALLO AL REGISTRAR PUEDE TUMBAR UN AVISO. Si la tabla no existe
 * todavía o la inserción falla, se anota en el log de la aplicación y el envío
 * sigue su curso: la observabilidad no puede costar un correo. Es la misma
 * regla que ya sigue el registro de ejecuciones.
 *
 * El nombre de la tabla y las columnas van literales para que el SQL se lea de
 * un vistazo; que coincidan con el mapeo de la entidad lo vigila su test.
 */
class NotificationRecorder
{
    private const TABLE = 'notification_log';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CronRunContext $cronRun,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Registra un aviso entregado al servicio de salida sin error.
     *
     * @param string       $channel Uno de NotificationLog::CHANNEL_*.
     * @param string       $kind    Tipo de aviso ("pickup_reminder").
     * @param string       $target  Dirección o descripción del destino.
     * @param string|null  $subject Asunto o título, para que el listado se lea.
     * @param Partner|null $partner A quién, cuando el canal lo sabe.
     */
    public function sent(string $channel, string $kind, string $target, ?string $subject = null, ?Partner $partner = null): void
    {
        $this->write(NotificationLog::STATUS_SENT, $channel, $kind, $target, $subject, $partner);
    }

    /**
     * Registra un intento que reventó.
     *
     * @param string       $error El motivo; se recorta al persistir.
     */
    public function failed(string $channel, string $kind, string $target, string $error, ?string $subject = null, ?Partner $partner = null): void
    {
        $this->write(NotificationLog::STATUS_FAILED, $channel, $kind, $target, $subject, $partner, $error);
    }

    /**
     * Registra un aviso que no llegó a salir porque el interruptor general de
     * envíos está apagado.
     *
     * Se apunta en vez de callar porque el silencio es justo lo que confunde:
     * con el maestro apagado, una tarea que "no mandó nada" se ve igual que una
     * que no encontró a nadie a quien avisar, y ese equívoco ya costó dos
     * semanas de diagnóstico cuando el reloj estaba caído.
     */
    public function discarded(string $channel, string $kind, string $target, string $reason, ?string $subject = null, ?Partner $partner = null): void
    {
        $this->write(NotificationLog::STATUS_DISCARDED, $channel, $kind, $target, $subject, $partner, $reason);
    }

    /**
     * La inserción. El recorte de textos vive en la entidad —una sola regla—
     * aunque aquí se escriba por DBAL.
     */
    private function write(
        string $status,
        string $channel,
        string $kind,
        string $target,
        ?string $subject,
        ?Partner $partner,
        ?string $error = null,
    ): void {
        $shaped = (new NotificationLog())->setSubject($subject)->setError($error)->setTarget($target);

        try {
            $this->connection()->insert(self::TABLE, [
                'sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'channel' => $channel,
                'kind' => mb_substr($kind, 0, 60),
                'subject' => $shaped->getSubject(),
                'partner_id' => $partner?->getId(),
                'target' => $shaped->getTarget(),
                'status' => $status,
                'error' => $shaped->getError(),
                'task_key' => $this->cronRun->taskKey(),
                'cron_run_id' => $this->cronRun->runId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo registrar el aviso {kind} a {target}: {error}', [
                'kind' => $kind,
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Conexión DBAL, pedida en cada uso: sigue viva aunque el EM se haya cerrado. */
    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
