<?php

namespace App\Service\Delivery;

use App\Entity\WeeklyBasket;

/**
 * El recordatorio de recogida al móvil y en la bandeja. Hermano de
 * {@see PickupReminderMailer}: el texto y la entrega viven en
 * {@see PickupNoticePusher}, que comparte con la confirmación; aquí sólo se
 * traduce cada cesta a su aviso y se ponen las clases de efecto del recordatorio.
 *
 * NO DECIDE DESTINATARIOS. Recibe las WeeklyBasket ya resueltas por el comando
 * (misma lista que el correo, ya filtrada de repartos cancelados), y el contexto
 * de cada una sale del mismo {@see PickupReminderMailer::contextFor()} que usa el
 * correo, así que las vías avisan exactamente de lo mismo y no hay forma de que
 * el push diga un día y el correo otro.
 */
class PickupReminderPusher
{
    /**
     * Clase de efecto con la que se apuntan estos avisos en el guardián de
     * idempotencia. Distinta de la del correo a propósito: son dos efectos, no
     * dos formas del mismo.
     */
    public const EFFECT_KIND = 'pickup_reminder_push';

    /**
     * Clase de efecto de la COPIA EN LA BANDEJA, separada de la del push y de la
     * del correo por el mismo motivo que ésas se separaron entre sí: con una sola
     * clase, mandar el push dejaría la copia por escrita para siempre y el socix
     * no la tendría nunca.
     */
    public const EFFECT_KIND_INBOX = 'pickup_reminder_inbox';

    public function __construct(
        private readonly PickupReminderMailer $mailer,
        private readonly PickupNoticePusher $notices,
    ) {
    }

    /**
     * Deja en la bandeja de cada socix de la lista la copia del recordatorio. Va
     * ANTES del correo y del push y no consulta preferencias; el porqué, en
     * {@see PickupNoticePusher::recordInbox()}.
     *
     * @param WeeklyBasket[] $weeklyBaskets Las cestas a avisar, ya resueltas.
     * @param bool           $resend        Orden explícita de repetir un aviso ya escrito.
     *
     * @return array{written: int, already: int} Copias escritas y socixs que ya la tenían.
     */
    public function recordInbox(array $weeklyBaskets, bool $resend = false): array
    {
        return $this->notices->recordInbox($this->noticesFor($weeklyBaskets), self::EFFECT_KIND_INBOX, $resend);
    }

    /**
     * Manda el recordatorio al móvil de cada socix de la lista que lo quiera y
     * aún no lo hubiera recibido para ese día.
     *
     * @param WeeklyBasket[] $weeklyBaskets Las cestas a avisar, ya resueltas.
     * @param bool           $resend        Orden explícita de repetir avisos ya emitidos.
     *
     * @return array{sent: int, devices: int, already: int} Socixs avisados, navegadores alcanzados y socixs que ya constaban avisados.
     */
    public function send(array $weeklyBaskets, bool $resend = false): array
    {
        return $this->notices->send(
            $this->noticesFor($weeklyBaskets),
            self::EFFECT_KIND,
            PickupReminderMailer::EFFECT_KIND,
            $resend,
        );
    }

    /**
     * El aviso de cada cesta, con el MISMO contexto que pinta el correo.
     *
     * @param WeeklyBasket[] $weeklyBaskets Las cestas a avisar.
     *
     * @return list<PickupNotice> Un aviso por cesta con socix.
     */
    private function noticesFor(array $weeklyBaskets): array
    {
        $contents = $this->mailer->contentsFor($weeklyBaskets);

        $notices = [];
        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            if (null === $partner?->getId()) {
                continue;
            }

            $context = $this->mailer->contextFor($wb, $contents[(int) $partner->getId()]);
            $notices[] = new PickupNotice(
                $partner,
                $context['contents'],
                $context['pickup_date'],
                $context['node_name'],
                $context['was_shifted'],
            );
        }

        return $notices;
    }
}
