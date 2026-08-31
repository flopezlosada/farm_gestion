<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Notification;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Entity\Partner;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Avisa por email a los socios cuyo cambio puntual de reparto
 * (PartnerDeliveryShift) se ANULÓ al cerrarse globalmente una semana.
 *
 * Es el destinatario del array `notify` que calcula {@see ClosureShiftReconciler}:
 * cuando un cierre de semana deshace un cambio que el socio había planificado
 * (mover su cesta hacia/desde esa semana) y no hay re-apunte posible, su cambio
 * desaparece y vuelve al patrón natural. Sin este aviso el socio no se enteraría
 * de que su plan se ha caído.
 *
 * Email transaccional disparado por el gestor al guardar el cierre: no lleva
 * toggle propio en /gestion/settings (a diferencia de los recordatorios por
 * cron) porque no es un envío recurrente. El interruptor general
 * {@see \App\Mailer\KillSwitchMailer} (email.enabled) lo sigue gobernando: con
 * él apagado no sale nada. Mismo patrón que {@see \App\Security\MagicLinkMailer}.
 *
 * Y DEJA COPIA EN LA BANDEJA, que es lo que hace verdad la frase de arriba sobre
 * que "sin este aviso el socio no se enteraría". Salía SÓLO por correo, y en el
 * padrón real la mayoría de las fichas no lo tienen informado: a esa gente se le
 * anulaba un cambio que había pedido y no se enteraba por ningún sitio. La copia
 * se escribe antes de mandar nada.
 */
class ClosureShiftNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
    ) {
    }

    /**
     * Envía a cada socio el aviso de que su cambio de reparto se ha anulado por
     * el cierre de la semana indicada. Salta en silencio a quien no tiene email
     * (datos heredados del dump pueden traer email null o vacío, y to('')
     * revienta en el envío).
     *
     * @param Partner[] $partners Socios a avisar (los de `notify` del reconciler).
     * @param Basket    $closedWeek Semana que se cerró (la del cierre global).
     * @return int Número de emails efectivamente enviados.
     */
    public function notifyCancelled(array $partners, Basket $closedWeek): int
    {
        // LA COPIA EN LA BANDEJA VA PRIMERO Y SIN MIRAR EL EMAIL. Este aviso salía
        // sólo por correo, y en el padrón real la mayoría de las fichas no tienen
        // correo informado: a esa gente se le anulaba un cambio que había pedido y
        // no se enteraba por ningún sitio. Quien no tenga cuenta de acceso sigue
        // sin recibir nada —no hay bandeja donde mirar—, pero ya no se pierde a
        // quien la tiene y no tiene correo.
        $this->recordInbox($partners, $closedWeek);

        $sent = 0;
        foreach ($partners as $partner) {
            $email = $partner->getemail();
            if ($email === null || trim($email) === '') {
                continue;
            }

            $message = (new TemplatedEmail())
                ->to($email)
                ->subject('Tu cambio de reparto se ha anulado · CSA Vega de Jarama')
                ->htmlTemplate('email/closure_shift_cancelled.html.twig')
                ->textTemplate('email/closure_shift_cancelled.txt.twig')
                ->context([
                    'partner' => $partner,
                    'closed_date' => $closedWeek->getDate(),
                ]);

            // El aviso corre tras guardar el cierre (en la misma petición del
            // admin): si el SMTP falla en un socio, se loguea y se sigue con el
            // resto, en vez de tumbar el redirect y dejar a los demás sin avisar.
            try {
                $this->mailer->send($message);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('No se pudo enviar el aviso de cierre a {email}: {error}', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Deja en la bandeja de cada socix la constancia de que su cambio se anuló.
     *
     * En una consulta para toda la lista y no una por socix: un cierre de semana
     * puede tocar a bastante gente de golpe.
     *
     * @param Partner[] $partners   lxs socixs a avisar
     * @param Basket    $closedWeek la semana que se cerró
     */
    private function recordInbox(array $partners, Basket $closedWeek): void
    {
        $recipients = $this->users->findByPartners(array_values($partners));
        if ([] === $recipients) {
            return;
        }

        $this->inbox->deliver(
            $recipients,
            Notification::KIND_SHIFT_CANCELLED,
            'Tu cambio de reparto se ha anulado',
            sprintf(
                'Se ha cerrado el reparto del %s y tu cambio no se ha podido mantener. Vuelves a tu semana de siempre.',
                $closedWeek->getDate()?->format('j/n') ?? 'esa semana',
            ),
        );
    }
}
