<?php

namespace App\Service\Email;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Entity\User;
use App\Repository\PartnerEventRepository;
use App\Repository\PartnerRepository;
use App\Service\AppSettings;
use App\Service\Delivery\DeliveryDeadline;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Construye el contexto para PREVISUALIZAR las plantillas de email del sistema
 * con datos reales de BBDD, sin enviar nada.
 *
 * Cada plantilla conocida ({@see self::TEMPLATES}) se resuelve a su ruta Twig y
 * un contexto poblado con registros reales (un socix de la base, los últimos
 * cambios registrados, el enlace de acceso del propio admin). Cuando no hay
 * datos reales que mostrar (p.ej. ningún cambio reciente para el resumen), cae
 * a un ejemplo plausible para que la maqueta siga siendo legible.
 *
 * La usa {@see \App\Controller\SettingsDiagnosticsController} para renderizar la
 * preview en el diagnóstico de envíos.
 */
class EmailPreviewFactory
{
    /** Plantillas previsualizables: clave de UI => ruta Twig HTML. */
    public const TEMPLATES = [
        'magic_link' => 'email/magic_link.html.twig',
        'pickup_reminder' => 'email/pickup_reminder.html.twig',
        'admin_summary' => 'email/admin_delivery_changes_summary.html.twig',
        'consumer_group_open' => 'email/consumer_group_open.html.twig',
    ];

    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly PartnerEventRepository $events,
        private readonly LoginLinkHandlerInterface $loginLinks,
        private readonly DeliveryChangeFormatter $formatter,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly AppSettings $settings,
        private readonly DeliveryDeadline $deadline,
    ) {
    }

    /**
     * Devuelve [template, context] para la clave dada, o null si no se conoce.
     *
     * @return array{template: string, context: array<string, mixed>}|null
     */
    public function build(string $which): ?array
    {
        $template = self::TEMPLATES[$which] ?? null;
        if ($template === null) {
            return null;
        }

        $context = match ($which) {
            'magic_link' => $this->magicLinkContext(),
            'pickup_reminder' => $this->pickupReminderContext(),
            'admin_summary' => $this->adminSummaryContext(),
            'consumer_group_open' => $this->consumerGroupOpenContext(),
        };

        return ['template' => $template, 'context' => $context];
    }

    /**
     * Enlace de acceso real del admin que está previsualizando (su propio magic
     * link); si por lo que sea no hay usuario, un enlace de muestra.
     *
     * @return array<string, mixed>
     */
    private function magicLinkContext(): array
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $details = $this->loginLinks->createLoginLink($user);

            return ['link' => $details->getUrl(), 'expires_at' => $details->getExpiresAt()];
        }

        return [
            'link' => 'https://csavegadejarama.org/login/check?example',
            'expires_at' => new \DateTimeImmutable('+30 minutes'),
        ];
    }

    /**
     * Recordatorio para un socix real de la base (el primero que haya),
     * fechado en el próximo viernes. La preview refleja el master switch
     * {@see AppSettings::EMAIL_PICKUP_REMINDER_LINKS} para que se vea el email
     * tal como saldrá según tengas los enlaces de acción activados o no (la
     * policy por-destinatario {@see PartnerAccessPolicy::canUseActionLinks} no
     * se previsualiza: varía socix a socix y no es lo que controla el toggle).
     *
     * @return array<string, mixed>
     */
    private function pickupReminderContext(): array
    {
        $partner = $this->partners->findOneBy([]) ?? (new Partner())->setName('Ejemplo');
        $pickupDate = new \DateTimeImmutable('next friday');

        return [
            'partner' => $partner,
            'modality' => 'quincenal',
            'pickup_date' => $pickupDate,
            // Punto de recogida real del socix de ejemplo (o Torremocha por defecto),
            // para que la preview muestre la forma del email consciente del nodo.
            'node_name' => $partner->getWeeklyBasketGroup()?->getNode()?->getName() ?? 'Torremocha',
            'was_shifted' => false,
            'can_act' => $this->settings->getBool(AppSettings::EMAIL_PICKUP_REMINDER_LINKS),
            'calendar_url' => $this->urls->generate('panel_calendar', [], UrlGeneratorInterface::ABSOLUTE_URL),
            // Deadline coherente con los ajustes de cierre, sobre la fecha de ejemplo.
            'deadline' => $this->deadline->fromPhysicalDate($pickupDate),
        ];
    }

    /**
     * Aviso de pedido abierto del grupo de consumo, con un pedido de ejemplo
     * construido en memoria.
     *
     * NO se busca un pedido real en la base, a diferencia de las demás previews:
     * el módulo va detrás de un feature-flag y lo normal mientras está en rodaje
     * es que no haya ninguno, así que la preview saldría vacía justo cuando más
     * se mira —al preparar el primer envío—. El socix sí es real, que es lo que
     * enseña cómo queda el saludo.
     *
     * @return array<string, mixed>
     */
    private function consumerGroupOpenContext(): array
    {
        $producer = (new Producer())->setName('Almazara de la Sierra');
        $product = (new ConsumerGroupProduct())->setName('Aceite de oliva virgen extra')->setUnit('garrafa de 5 L');
        $producer->addProduct($product);

        $round = (new ConsumerGroupRound())
            ->setTitle('Aceite de la nueva cosecha')
            ->setProducer($producer)
            ->setDescription('Cosecha temprana, sin filtrar. Se recoge con la cesta.')
            ->setOrdersCloseAt(new \DateTime('+10 days'))
            ->setDeliveryDate(new \DateTime('+17 days'));
        $round->addItem(new ConsumerGroupRoundItem($round, $product, '42.00'));

        return [
            'partner' => $this->partners->findOneBy([]) ?? (new Partner())->setName('Ejemplo'),
            'round' => $round,
            'url' => $this->urls->generate('panel_consumer_group_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'preferences_url' => $this->urls->generate('panel_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'can_act' => true,
        ];
    }

    /**
     * Resumen con los cambios reales del último año; si no hay ninguno, un par
     * de filas de ejemplo para que la tabla no salga vacía.
     *
     * @return array<string, mixed>
     */
    private function adminSummaryContext(): array
    {
        $since = new \DateTimeImmutable('-1 year');
        $events = $this->events->findByTypesSince(DeliveryChangeFormatter::RELEVANT_TYPES, $since);

        $rows = array_map(fn ($e) => $this->formatter->renderableRow($e), $events);
        if ($rows === []) {
            $rows = [
                ['when' => (new \DateTimeImmutable('-2 days'))->format('d/m/Y H:i'), 'partner' => 'García, Lucía', 'type' => 'No recoge cesta', 'detail' => ''],
                ['when' => (new \DateTimeImmutable('-1 day'))->format('d/m/Y H:i'), 'partner' => 'Ruiz, Marcos', 'type' => 'Cambia de nodo', 'detail' => 'Madrid → Sierra'],
            ];
            $since = new \DateTimeImmutable('-7 days');
        }

        return ['since' => $since, 'rows' => $rows];
    }
}
