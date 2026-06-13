<?php

namespace App\Service\Email;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerEventRepository;
use App\Repository\PartnerRepository;
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
    ];

    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly PartnerEventRepository $events,
        private readonly LoginLinkHandlerInterface $loginLinks,
        private readonly DeliveryChangeFormatter $formatter,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
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
     * fechado en el próximo viernes y con los enlaces de acción activos para
     * que se vea el botón.
     *
     * @return array<string, mixed>
     */
    private function pickupReminderContext(): array
    {
        $partner = $this->partners->findOneBy([]) ?? (new Partner())->setName('Ejemplo');

        return [
            'partner' => $partner,
            'modality' => 'quincenal',
            'pickup_date' => new \DateTimeImmutable('next friday'),
            'was_shifted' => false,
            'can_act' => true,
            'calendar_url' => $this->urls->generate('panel_calendar', [], UrlGeneratorInterface::ABSOLUTE_URL),
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
