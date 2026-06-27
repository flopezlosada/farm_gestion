<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
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
 */
class ClosureShiftNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
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
}
