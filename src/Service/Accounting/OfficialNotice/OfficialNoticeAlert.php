<?php

namespace App\Service\Accounting\OfficialNotice;

use App\Entity\Notification;
use App\Entity\OfficialNotice;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;

/**
 * El aviso urgente de que ha llegado una notificación oficial: al móvil de quien
 * lleva la contabilidad y a la bandeja de todo el que puede marcarla como revisada.
 *
 * EL TEXTO ES FIJO. No copia asunto, cuerpo ni enlaces del correo: el correo que
 * suplanta al organismo es justo el que trae el enlace bueno de imitar, y un aviso
 * de la propia app que lo repitiera le daría credibilidad. Lo único variable es el
 * nombre del organismo, que sale de la configuración y no del correo.
 *
 * Push a los responsables (sin contar al admin por jerarquía) y bandeja a todos los
 * que alcanzan el permiso, admins incluidos, como el resto de avisos de gestión
 * ({@see StaffAudience::responsiblesFor()}).
 */
class OfficialNoticeAlert
{
    /** Quien puede marcarla como revisada: el permiso de escribir en contabilidad. */
    public const ROLE = 'ROLE_GESTION_CONTABILIDAD_EDIT';

    /** Qué hacer, sin pasar por el correo. */
    public const HOW_TO = 'Escribe tú dehu.redsara.es (o la sede del organismo) y entra con certificado; no uses el enlace del correo.';

    /** Qué pasa si no se hace. */
    public const DEEMED_NOTIFIED = 'Si nadie la abre en 10 días naturales desde que se puso a disposición, cuenta como notificada y corren los plazos.';

    public function __construct(
        private readonly StaffAudience $staff,
        private readonly NotificationInbox $inbox,
        private readonly PushSender $push,
        private readonly NotificationLink $link,
    ) {
    }

    /**
     * Avisa de una notificación recién llegada.
     *
     * @param OfficialNotice $notice El aviso ya guardado.
     *
     * @return int Cuántos avisos se han dejado en bandejas.
     */
    public function send(OfficialNotice $notice): int
    {
        $title = sprintf('Notificación oficial (%s)', $notice->getAgency());
        $body = self::body($notice->getAgency());

        $delivered = $this->inbox->deliver($this->staff->withRole(self::ROLE), Notification::KIND_ACCOUNTING_OFFICIAL_NOTICE, $title, $body);
        $this->push->sendToMany(
            $this->staff->responsiblesFor(self::ROLE),
            $title,
            $body,
            $this->link->pathForKind(Notification::KIND_ACCOUNTING_OFFICIAL_NOTICE),
            'accounting_official_notice',
        );

        return $delivered;
    }

    /**
     * El texto del aviso.
     *
     * @param string $agency Organismo, de la configuración.
     *
     * @return string Las tres frases: qué ha llegado, qué hacer y qué pasa si no.
     */
    public static function body(string $agency): string
    {
        return sprintf('Ha llegado un aviso de notificación oficial (%s). %s %s', $agency, self::HOW_TO, self::DEEMED_NOTIFIED);
    }
}
