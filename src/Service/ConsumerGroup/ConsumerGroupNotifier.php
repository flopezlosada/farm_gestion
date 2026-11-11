<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Entity\Partner;
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
            if (self::familyRecipients($order) !== []) {
                ++$withEmail;
            }
        }

        return ['total' => $total, 'withEmail' => $withEmail];
    }

    /**
     * Envía el aviso de confirmación a las familias apuntadas: un correo a cada
     * persona de la familia con dirección. Devuelve el resultado para dar feedback a
     * la comisión (`sent` cuenta correos; `skippedNoEmail`, pedidos sin nadie a quien
     * escribir).
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

            $recipients = self::familyRecipients($order);
            if ($recipients === []) {
                ++$skippedNoEmail;
                continue;
            }

            // Un correo por persona, con su nombre en el saludo.
            foreach ($recipients as $member) {
                $message = (new TemplatedEmail())
                    ->to(trim((string) $member->getEmail()))
                    ->subject('Tu pedido del grupo de consumo está confirmado')
                    ->htmlTemplate('email/consumer_group_confirmed.html.twig')
                    ->textTemplate('email/consumer_group_confirmed.txt.twig')
                    ->context([
                        'partner'   => $member,
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
                        'partner' => $member->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['enabled' => true, 'sent' => $sent, 'skippedNoEmail' => $skippedNoEmail, 'failed' => $failed];
    }

    /**
     * Avisa al productor de que su ronda está confirmada: ya puede darla por
     * buena y prepararla. Se llama tanto si se confirmó sola al alcanzar el
     * mínimo automático ({@see MinimumAutoConfirmer}) como si la confirmó la
     * comisión a mano ({@see \App\Controller\ConsumerGroupController::confirm()}
     * con el email activado) — al productor le da igual cómo se llegó a la
     * confirmación. Solo si el productor tiene email; si no lo tiene, no hay
     * nada que enviar (no es un fallo).
     *
     * @return array{enabled: bool, sent: bool}
     */
    public function notifyProducerConfirmed(ConsumerGroupRound $round): array
    {
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)) {
            return ['enabled' => false, 'sent' => false];
        }

        $email = (string) ($round->getProducer()?->getEmail() ?? '');
        if ($email === '') {
            return ['enabled' => true, 'sent' => false];
        }

        $message = (new TemplatedEmail())
            ->to($email)
            ->subject(sprintf('Pedido «%s» confirmado', $round->getTitle()))
            ->htmlTemplate('email/consumer_group_producer_confirmed.html.twig')
            ->textTemplate('email/consumer_group_producer_confirmed.txt.twig')
            ->context(['round' => $round]);

        try {
            $this->mailer->send($message);
            return ['enabled' => true, 'sent' => true];
        } catch (\Throwable $e) {
            $this->logger->error('Fallo al avisar al productor de la confirmación del pedido.', [
                'round' => $round->getId(),
                'producer' => $round->getProducer()?->getId(),
                'error' => $e->getMessage(),
            ]);

            return ['enabled' => true, 'sent' => false];
        }
    }

    /**
     * A quién se le cuenta un pedido: a todas las personas de la familia con correo,
     * no sólo a la que lo encabeza. El pedido es de la familia y puede haberlo hecho
     * cualquiera de ellas; si sólo se avisara a la principal, quien pidió no se
     * enteraría. Una por dirección: hay familias que comparten una.
     *
     * @return list<Partner>
     */
    public static function familyRecipients(ConsumerGroupOrder $order): array
    {
        $byEmail = [];
        foreach ($order->getPartner()?->getFamilyMembers() ?? [] as $member) {
            $email = mb_strtolower(trim((string) $member->getEmail()));
            if ($email !== '' && !isset($byEmail[$email])) {
                $byEmail[$email] = $member;
            }
        }

        return array_values($byEmail);
    }
}
