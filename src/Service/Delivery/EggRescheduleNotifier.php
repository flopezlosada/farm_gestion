<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Avisa a quien se queda sin los huevos de un reparto porque gestión los retiró
 * o los trasladó en lote ({@see NodeEggRescheduler}).
 *
 * El aviso importa más que en otros cambios: el socio no ha pedido nada y, si no
 * se le dice, se planta en el punto de recogida esperando su docena. Por eso se
 * avisa también cuando los huevos se trasladan — la semana que viene tendrá el
 * doble, pero esta no tiene ninguno.
 *
 * Email transaccional disparado por el gestor al aplicar la operación: no lleva
 * toggle propio en /gestion/settings (no es un envío recurrente), pero el
 * interruptor general {@see \App\Mailer\KillSwitchMailer} (email.enabled) lo
 * gobierna igual que a los demás. Mismo patrón que {@see ClosureShiftNotifier}.
 *
 * Y DEJA COPIA EN LA BANDEJA, que es lo que hace verdad el párrafo de arriba. Este
 * aviso salía SÓLO por correo, y en el padrón real la mayoría de las fichas no
 * tienen correo informado: a esa gente se le retiraban los huevos y se plantaba en
 * el punto de recogida esperando su docena, que es exactamente lo que este
 * servicio existe para evitar. La copia se escribe antes de mandar nada.
 *
 * A LXS VOLUNTARIXS DEL ALBERGUE no se les deja copia: no son socixs, no tienen
 * cuenta de acceso ni bandeja, y su vía sigue siendo el correo.
 */
class EggRescheduleNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
    ) {
    }

    /**
     * Envía el aviso a cada afectado. Salta en silencio a quien no tiene email
     * (los datos heredados traen emails nulos o vacíos, y to('') revienta).
     *
     * @param list<array{kind: 'partner'|'helper', name: string, partner: \App\Entity\Partner|null, helper: \App\Entity\Helper|null}> $recipients
     *        Las filas `notify` que devuelve {@see NodeEggRescheduler::apply}.
     * @param Basket      $from Semana de la que se retiraron los huevos.
     * @param Basket|null $to   Semana a la que se trasladaron, o null si no se recolocaron.
     * @return int Número de avisos efectivamente enviados.
     */
    public function notify(array $recipients, Basket $from, ?Basket $to): int
    {
        $this->recordInbox($recipients, $to);

        $sent = 0;
        foreach ($recipients as $recipient) {
            $address = $recipient['kind'] === 'helper'
                ? $recipient['helper']?->getEmail()
                : $recipient['partner']?->getemail();
            if ($address === null || trim($address) === '') {
                continue;
            }

            $message = (new TemplatedEmail())
                ->to($address)
                ->subject($to !== null
                    ? 'Tus huevos cambian de semana · CSA Vega de Jarama'
                    : 'Esta semana no hay huevos · CSA Vega de Jarama')
                ->htmlTemplate('email/egg_reschedule.html.twig')
                ->textTemplate('email/egg_reschedule.txt.twig')
                ->context([
                    'name' => $recipient['name'],
                    'from_date' => $from->getDate(),
                    'to_date' => $to?->getDate(),
                    'is_helper' => $recipient['kind'] === 'helper',
                ]);

            // Corre en la misma petición del gestor: si el SMTP falla con uno,
            // se loguea y se sigue con el resto, en vez de tumbar el redirect y
            // dejar a los demás sin avisar.
            try {
                $this->mailer->send($message);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('No se pudo avisar del cambio de huevos a {email}: {error}', [
                    'email' => $address,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Deja en la bandeja de cada socix afectadx la constancia del cambio.
     *
     * Sólo a lxs socixs: las filas de tipo 'helper' son voluntarixs del albergue,
     * que no tienen cuenta de acceso ni bandeja donde mirar.
     *
     * El texto es el mismo para todo el grupo —lo que cambia entre personas es el
     * nombre del saludo del correo, no el hecho—, así que va en un solo lote.
     *
     * @param list<array{kind: 'partner'|'helper', name: string, partner: Partner|null, helper: \App\Entity\Helper|null}> $recipients lxs afectadxs
     * @param Basket|null $to la semana a la que se trasladaron, o null si no se recolocaron
     */
    private function recordInbox(array $recipients, ?Basket $to): void
    {
        $partners = [];
        foreach ($recipients as $recipient) {
            $partner = 'partner' === $recipient['kind'] ? $recipient['partner'] : null;
            if (null === $partner) {
                continue;
            }

            // Sin repetir: una misma persona puede venir dos veces en la lista
            // (dos entregas del mismo día). Se indexa por id, y por identidad de
            // objeto cuando aún no lo tiene — descartar por no tener id sería
            // perder el aviso en silencio, que es peor que un posible duplicado.
            $partners[$partner->getId() ?? 'obj-' . spl_object_id($partner)] = $partner;
        }

        if ([] === $partners) {
            return;
        }

        $users = $this->users->findByPartners(array_values($partners));
        if ([] === $users) {
            return;
        }

        $this->inbox->deliver(
            $users,
            Notification::KIND_EGGS_RESCHEDULED,
            null !== $to ? 'Tus huevos cambian de semana' : 'Esta semana no hay huevos',
            null !== $to
                ? sprintf('Los recogerás el %s, junto con los de esa semana.', $to->getDate()?->format('j/n') ?? 'próximo reparto')
                : 'Este reparto no lleva huevos. No hace falta que los esperes en el punto de recogida.',
        );
    }
}
