<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Service\AppSettings;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Avisa por email a las socias apuntadas cuando una ronda del grupo de consumo se
 * confirma ("tu pedido está confirmado y se entrega el … con la cesta"). Envío en
 * bucle (un email por socia): un fallo suelto NO aborta el resto (se cuenta y se
 * registra). El interruptor general {@see AppSettings::EMAIL_ENABLED} corta el
 * envío —el tablón/panel del socio sigue mostrando el pedido igual—; además, como
 * el mailer real está decorado por {@see \App\Mailer\KillSwitchMailer}, ningún
 * correo llegaría al transporte aunque nos saltáramos esta comprobación.
 *
 * Solo se avisa a socias con pedido NO vacío y email válido. Es comunicación
 * legítima de la asociación a sus socias (LOPD): no se añaden terceros.
 */
class ConsumerGroupNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Recuento de destinatarios para la pantalla de confirmación previa: cuántas
     * socias hay apuntadas y cuántas de ellas recibirán email (tienen dirección).
     *
     * @return array{total: int, withEmail: int}
     */
    public function recipientStats(ConsumerGroupRound $round): array
    {
        $total = 0;
        $withEmail = 0;
        foreach ($round->getOrders() as $order) {
            if ($order->isEmpty()) {
                continue;
            }
            ++$total;
            if ((string) $order->getPartner()?->getEmail() !== '') {
                ++$withEmail;
            }
        }

        return ['total' => $total, 'withEmail' => $withEmail];
    }

    /**
     * Envía el aviso de confirmación a las socias apuntadas con email. Devuelve el
     * resultado para dar feedback a la comisión.
     *
     * @return array{enabled: bool, sent: int, skippedNoEmail: int, failed: int}
     */
    public function notifyConfirmed(ConsumerGroupRound $round): array
    {
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)) {
            return ['enabled' => false, 'sent' => 0, 'skippedNoEmail' => 0, 'failed' => 0];
        }

        $panelUrl = $this->urls->generate('panel_consumer_group_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $sent = 0;
        $skippedNoEmail = 0;
        $failed = 0;

        foreach ($round->getOrders() as $order) {
            if ($order->isEmpty()) {
                continue;
            }

            $partner = $order->getPartner();
            $email = (string) ($partner?->getEmail() ?? '');
            if ($email === '') {
                ++$skippedNoEmail;
                continue;
            }

            $message = (new TemplatedEmail())
                ->to($email)
                ->subject('Tu pedido del grupo de consumo está confirmado')
                ->htmlTemplate('email/consumer_group_confirmed.html.twig')
                ->textTemplate('email/consumer_group_confirmed.txt.twig')
                ->context([
                    'partner'   => $partner,
                    'round'     => $round,
                    'order'     => $order,
                    'panel_url' => $panelUrl,
                ]);

            try {
                $this->mailer->send($message);
                ++$sent;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Fallo al enviar el aviso de grupo de consumo.', [
                    'round' => $round->getId(),
                    'partner' => $partner?->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['enabled' => true, 'sent' => $sent, 'skippedNoEmail' => $skippedNoEmail, 'failed' => $failed];
    }
}
