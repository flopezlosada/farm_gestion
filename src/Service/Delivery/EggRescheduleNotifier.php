<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
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
 */
class EggRescheduleNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
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
}
