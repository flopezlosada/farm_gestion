<?php

namespace App\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\WeeklyBasket;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use App\Service\Cron\EffectLedger;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Construye y envía el recordatorio de recogida CONSCIENTE DEL NODO a partir de
 * WeeklyBasket ya materializadas. Cada email habla de la fecha física y el nodo
 * de ESE socix (Madrid recoge el miércoles en Cascorro/Midori, la Sierra el
 * viernes en Torremocha), no de un viernes común en la granja. Un reparto
 * desplazado por festivo se comunica en su fecha real y con el aviso de
 * desplazamiento.
 *
 * La lógica de contenido vive aquí (no en el comando) para poder inspeccionarla
 * en el dry-run y en los tests sin enviar correo.
 */
class PickupReminderMailer
{
    /**
     * Clase de efecto con la que se apuntan estos avisos en el guardián de
     * idempotencia ({@see EffectLedger}).
     */
    public const EFFECT_KIND = 'pickup_reminder';

    /** Etiqueta de modalidad por id de BasketShare (solo quincenal y mensual reciben aviso). */
    private const MODALITY_BY_SHARE = [
        BasketShare::ID_BIWEEKLY => 'quincenal',
        BasketShare::ID_MONTHLY => 'mensual',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly DeliveryDeadline $deadline,
        private readonly EffectLedger $ledger,
        private readonly NotificationPreferences $preferences,
    ) {
    }

    /**
     * Envía un recordatorio por cada WeeklyBasket cuyo socix tenga email. Los
     * que no tienen email se saltan en silencio (filas heredadas del dump de
     * prod). No decide destinatarios: recibe la lista ya resuelta.
     *
     * Cada correo se emite a través del guardián de idempotencia con la clave
     * "este socix, este día de reparto", así que repetir la tarea no reenvía
     * nada. La clave es el SOCIX y no la cesta a propósito: quien tiene una
     * cesta extra puntual el mismo día aparece dos veces en la lista, y con la
     * cesta como clave recibiría dos correos idénticos.
     *
     * La clave se construye con el identificador del socix, lo que da por hecho
     * que las cestas vienen de la base de datos (así las trae el finder del
     * comando). Con entidades no persistidas la referencia no sería única.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @param bool           $resend Orden explícita de repetir avisos ya emitidos.
     * @return array{sent: int, skipped: int, already: int} Enviados, saltados por falta de email y ya avisados antes.
     */
    public function send(array $weeklyBaskets, bool $resend = false): array
    {
        // Los enlaces de acción exigen login: solo se pintan si el master switch
        // está encendido Y el socix puede entrar (tiene cuenta, o el alta está
        // abierta). Se lee una vez; canUseActionLinks resuelve por socix.
        $linksEnabled = $this->settings->getBool(AppSettings::EMAIL_PICKUP_REMINDER_LINKS);

        $sent = 0;
        $skipped = 0;
        $already = 0;
        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            $email = $partner?->getEmail();
            if (!$email) {
                $skipped++;
                continue;
            }

            // Quien ha dicho que no quiere este aviso por correo no lo recibe, y
            // se cuenta como saltado y no como enviado. Antes esta comprobación
            // no existía: si eras quincenal o mensual y tenías email, te llegaba,
            // y la única forma de pararlo era pedírselo a administración.
            if (!$this->preferences->wants($partner, NotificationTopic::PICKUP, NotificationTopic::CHANNEL_EMAIL)) {
                $skipped++;
                continue;
            }

            $emitted = $this->ledger->once(
                self::EFFECT_KIND,
                sprintf('partner-%d', (int) $partner->getId()),
                $this->pickupDate($wb),
                fn () => $this->mailer->send(
                    (new TemplatedEmail())
                        ->to($email)
                        ->subject('Recordatorio: tu cesta de la CSA Vega de Jarama')
                        ->htmlTemplate('email/pickup_reminder.html.twig')
                        ->textTemplate('email/pickup_reminder.txt.twig')
                        ->context($this->contextFor($wb, $linksEnabled))
                ),
                $email,
                $resend,
            );

            $emitted ? $sent++ : $already++;
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'already' => $already];
    }

    /**
     * Contexto del email para un WeeklyBasket: fecha física, nodo, modalidad y
     * si el reparto está desplazado respecto al día habitual del nodo. Público
     * para que el dry-run del comando y los tests inspeccionen a quién/qué se
     * avisaría sin enviar nada.
     *
     * @return array<string, mixed>
     */
    public function contextFor(WeeklyBasket $wb, bool $linksEnabled = false): array
    {
        $node = $wb->getWeeklyBasketGroup()?->getNode();
        $pickupDate = $this->pickupDate($wb);
        $partner = $wb->getPartner();

        return [
            'partner' => $partner,
            'modality' => self::MODALITY_BY_SHARE[$wb->getBasketShare()?->getId()] ?? 'de la CSA',
            'pickup_date' => $pickupDate,
            'node_name' => $node?->getName(),
            'was_shifted' => $this->wasShifted($pickupDate, $node),
            'can_act' => $linksEnabled && $partner !== null && $this->accessPolicy->canUseActionLinks($partner),
            'calendar_url' => $this->urlGenerator->generate('panel_calendar', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'deadline' => $this->deadline->fromPhysicalDate($pickupDate),
        ];
    }

    /**
     * Fecha física de recogida del WeeklyBasket como immutable. El finder ancla
     * en delivery_date, así que aquí ya viene poblada; el fallback al viernes del
     * Basket cubre filas heredadas sin materializar (defensivo, no camino real).
     */
    private function pickupDate(WeeklyBasket $wb): \DateTimeImmutable
    {
        $date = $wb->getDeliveryDate() ?? $wb->getBasket()?->getDate() ?? new \DateTimeImmutable('today');

        return $date instanceof \DateTimeImmutable ? $date : \DateTimeImmutable::createFromInterface($date);
    }

    /**
     * ¿La fecha física difiere del día de la semana habitual del nodo? Señala un
     * reparto desplazado (festivo) para pintar el aviso "no es el día habitual".
     */
    private function wasShifted(\DateTimeImmutable $pickupDate, ?Node $node): bool
    {
        $weekday = $node?->getDeliveryWeekday();

        return $weekday !== null && (int) $pickupDate->format('N') !== $weekday;
    }
}
