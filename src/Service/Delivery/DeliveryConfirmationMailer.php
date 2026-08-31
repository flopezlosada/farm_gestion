<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Cron\EffectLedger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Confirma a cada socix qué le queda registrado para el reparto que acaba de
 * cerrar su plazo: o recoge tal día en tal sitio, o esta semana no recoge.
 *
 * POR QUÉ TAMBIÉN A QUIEN NO RECOGE, que es lo que le da sentido. El aviso nace
 * de una petición concreta: "habrá gente que crea que ha cambiado algo y no lo
 * haya hecho". Escribir sólo a quien tiene cesta deja fuera justo la mitad de ese
 * caso — quien pidió mover su cesta a otra semana y no le salió no aparece en el
 * listado, no recibiría nada, y no recibir nada es indistinguible de "esta semana
 * no me tocaba". El silencio no es información.
 *
 * Pero tampoco se escribe a todo el que no recoge: a un quincenal que no ha
 * tocado nada, decirle cada quince días que no le toca es ruido. Entran quienes
 * PIDIERON algo ({@see PartnerDeliveryShift} saliente de ese ciclo) y por eso no
 * están en la lista; a ésos el correo les confirma que su cambio se aplicó.
 *
 * Va DESPUÉS del cierre, y por eso no se pisa con el recordatorio de recogida
 * ({@see PickupReminderMailer}), que sale antes: aquél dice "aún puedes cambiar",
 * éste dice "esto es lo que hay". Con los valores por defecto son días distintos.
 */
class DeliveryConfirmationMailer
{
    /**
     * Clase de efecto con la que se apuntan estas confirmaciones en el guardián
     * de idempotencia ({@see EffectLedger}). Separada de la del recordatorio: son
     * dos avisos distintos y uno no puede dar el otro por enviado.
     */
    public const EFFECT_KIND = 'delivery_confirmation';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly WeeklyBasketRepository $weeklyBaskets,
        private readonly PartnerDeliveryShiftRepository $shifts,
        private readonly EffectLedger $ledger,
    ) {
    }

    /**
     * A quién hay que confirmarle qué, para un reparto concreto de un nodo.
     *
     * Público para que el dry-run del comando y los tests puedan ver a quién se
     * escribiría sin enviar nada.
     *
     * @param Node               $node         Nodo cuyo plazo acaba de cerrar.
     * @param Basket             $basket       Ciclo del reparto.
     * @param \DateTimeImmutable $physicalDate Fecha física del reparto en ese nodo.
     * @return list<array{partner: Partner, picks: bool, pickup_date: \DateTimeImmutable, node_name: ?string, was_shifted: bool}>
     */
    public function audienceFor(Node $node, Basket $basket, \DateTimeImmutable $physicalDate): array
    {
        $audience = [];

        foreach ($this->weeklyBaskets->findForNodeAndBasket($node, $basket) as $weeklyBasket) {
            $partner = $weeklyBasket->getPartner();
            if ($partner === null) {
                continue;
            }

            // Por id y no acumulando: quien tiene una cesta extra puntual el mismo
            // día sale dos veces del finder, y son una sola persona a la que
            // confirmar una sola cosa.
            $audience[(int) $partner->getId()] = $this->entry($partner, true, $physicalDate, $node);
        }

        foreach ($this->shifts->findAllOutgoingFromBasket($basket) as $shift) {
            $partner = $shift->getPartner();
            if ($partner === null || isset($audience[(int) $partner->getId()])) {
                continue;
            }

            // Sólo los movimientos de la CESTA ENTERA sacan a alguien del reparto.
            // Un cambio de un componente suelto (los huevos a otra semana) no, y a
            // esa persona ya se le confirma por la rama de arriba si recoge.
            if (!$shift->isWholeDelivery()) {
                continue;
            }

            // El nodo del socix, no el del reparto: el finder de movimientos es de
            // todo el ciclo y aquí sólo tocan los de este nodo, que es el que ha
            // cerrado su plazo.
            if ($partner->getWeeklyBasketGroup()?->getNode() !== $node) {
                continue;
            }

            $audience[(int) $partner->getId()] = $this->entry($partner, false, $physicalDate, $node);
        }

        return array_values($audience);
    }

    /**
     * Manda una confirmación por destinatario con email. Quien no tiene email se
     * salta en silencio (filas heredadas del dump de producción).
     *
     * La clave del apunte es "este socix, este día de reparto", igual que en el
     * recordatorio: repetir la tarea no reenvía nada.
     *
     * @param list<array{partner: Partner, picks: bool, pickup_date: \DateTimeImmutable, node_name: ?string, was_shifted: bool}> $audience
     * @param bool $resend Orden explícita de repetir avisos ya emitidos.
     * @return array{sent: int, skipped: int, already: int}
     */
    public function send(array $audience, bool $resend = false): array
    {
        $sent = 0;
        $skipped = 0;
        $already = 0;

        foreach ($audience as $entry) {
            $email = $entry['partner']->getEmail();
            if (!$email) {
                ++$skipped;
                continue;
            }

            $emitted = $this->ledger->once(
                self::EFFECT_KIND,
                sprintf('partner-%d', (int) $entry['partner']->getId()),
                $entry['pickup_date'],
                fn () => $this->mailer->send(
                    (new TemplatedEmail())
                        ->to($email)
                        ->subject($entry['picks']
                            ? 'Tu cesta de esta semana · CSA Vega de Jarama'
                            : 'Esta semana no recoges cesta · CSA Vega de Jarama')
                        ->htmlTemplate('email/delivery_confirmation.html.twig')
                        ->textTemplate('email/delivery_confirmation.txt.twig')
                        ->context($entry)
                ),
                $email,
                $resend,
            );

            if ($emitted) {
                ++$sent;
            } else {
                ++$already;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'already' => $already];
    }

    /**
     * Una entrada de la audiencia.
     *
     * @param Partner            $partner      Socix a quien se confirma.
     * @param bool               $picks        Si recoge cesta en este reparto.
     * @param \DateTimeImmutable $physicalDate Fecha física del reparto.
     * @param Node               $node         Nodo del reparto.
     * @return array{partner: Partner, picks: bool, pickup_date: \DateTimeImmutable, node_name: ?string, was_shifted: bool}
     */
    private function entry(Partner $partner, bool $picks, \DateTimeImmutable $physicalDate, Node $node): array
    {
        return [
            'partner' => $partner,
            'picks' => $picks,
            'pickup_date' => $physicalDate,
            'node_name' => $node->getName(),
            // Mismo criterio que el recordatorio: si la fecha física no cae en el
            // día habitual del nodo, el reparto va desplazado (festivo) y hay que
            // decirlo, que es cuando la gente se planta el día que no era.
            'was_shifted' => $node->getDeliveryWeekday() !== null
                && (int) $physicalDate->format('N') !== $node->getDeliveryWeekday(),
        ];
    }
}
