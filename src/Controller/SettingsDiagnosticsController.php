<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AppSettings;
use App\Service\Email\EmailPreviewFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pantalla de diagnóstico de envíos ({@see /gestion/settings/diagnostics}).
 *
 * Pensada para PROBAR sin SSH lo que de otro modo solo se puede ejercitar por
 * consola: que el SMTP del entorno conecta (email de prueba) y que los
 * disparadores de cron hacen lo esperado (recordatorio de recogida, resumen a
 * admin). Lanza los comandos en proceso y muestra su salida tal cual la verías
 * en una terminal.
 *
 * En staging conviene combinarla con la redirección de pruebas
 * ({@see AppSettings::EMAIL_REDIRECT_TO}), editable en esta misma pantalla: así
 * el envío real se puede observar llegando a una bandeja propia sin riesgo de
 * escribir a socixs reales.
 *
 * Sólo administración. Los botones de envío real llevan confirmación en la UI.
 */
#[Route('/gestion/settings/diagnostics')]
#[IsGranted('ROLE_ADMIN')]
class SettingsDiagnosticsController extends AbstractController
{
    /**
     * Disparadores de cron que la pantalla puede lanzar. Whitelist por
     * identificador → mapea al nombre real del comando, para que el form nunca
     * pueda pedir la ejecución de un comando arbitrario.
     */
    private const COMMANDS = [
        'reminder' => 'app:send-pickup-reminders',
        'summary' => 'app:send-admin-delivery-changes-summary',
    ];

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly EmailPreviewFactory $previewFactory,
    ) {
    }

    /**
     * Pinta los bloques de diagnóstico. La salida del último comando lanzado
     * (si lo hubo) viaja por sesión para respetar Post-Redirect-Get: un F5 no
     * relanza el envío.
     */
    #[Route('', name: 'settings_diagnostics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $result = $session->get('diagnostics_result');
        $session->remove('diagnostics_result');

        return $this->render('settings/diagnostics.html.twig', [
            'default_email' => $this->currentUserEmail(),
            'redirect_to' => $this->settings->getString(AppSettings::EMAIL_REDIRECT_TO),
            'result' => $result,
        ]);
    }

    /**
     * Guarda la redirección de pruebas ({@see AppSettings::EMAIL_REDIRECT_TO}):
     * con valor, todos los emails irán solo a esa(s) dirección(es); vacío, se
     * desactiva. Avisa de que en producción debe quedar vacío.
     */
    #[Route('/redirect', name: 'settings_diagnostics_redirect', methods: ['POST'])]
    public function saveRedirect(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('diagnostics_redirect', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_diagnostics');
        }

        $value = trim((string) $request->request->get('redirect_to'));

        // Admite varias direcciones separadas por comas. Se valida cada una
        // antes de persistir: un email malformado reventaría luego en
        // `new Address(...)` dentro del RedirectRecipientsListener, en plena
        // cadena de envío real.
        if ($value !== '') {
            foreach (array_map('trim', explode(',', $value)) as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $this->addFlash('warning', sprintf('"%s" no es una dirección de email válida; no se ha guardado.', $addr));
                    return $this->redirectToRoute('settings_diagnostics');
                }
            }
        }

        $this->settings->setString(AppSettings::EMAIL_REDIRECT_TO, $value);

        $this->addFlash('success', $value === ''
            ? 'Redirección desactivada: cada email irá a su destinatario real.'
            : sprintf('Redirección activada: todos los emails irán a %s.', $value));

        return $this->redirectToRoute('settings_diagnostics');
    }

    /**
     * Renderiza una plantilla de email con datos reales para previsualizarla en
     * el navegador, sin enviar nada. La respuesta ES el HTML del email.
     */
    #[Route('/preview/{which}', name: 'settings_diagnostics_preview', methods: ['GET'])]
    public function preview(string $which): Response
    {
        $preview = $this->previewFactory->build($which);
        if ($preview === null) {
            throw $this->createNotFoundException('Plantilla de previsualización desconocida.');
        }

        return $this->render($preview['template'], $preview['context']);
    }

    /**
     * Envía un email de prueba a la dirección indicada (por defecto la del
     * admin logueado) para verificar la conexión SMTP del entorno end-to-end.
     */
    #[Route('/test-email', name: 'settings_diagnostics_test_email', methods: ['POST'])]
    public function testEmail(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('diagnostics_test_email', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_diagnostics');
        }

        $to = trim((string) $request->request->get('email')) ?: $this->currentUserEmail();
        if ($to === '') {
            $this->addFlash('warning', 'Indica una dirección de destino (tu cuenta no tiene email).');
            return $this->redirectToRoute('settings_diagnostics');
        }

        $message = (new Email())
            ->to($to)
            ->subject('Email de prueba · CSA Vega de Jarama')
            ->text("Este es un email de prueba enviado desde /gestion/settings/diagnostics.\n\nSi lo recibes, el envío de correo de este entorno funciona.")
            ->html('<p>Este es un <strong>email de prueba</strong> enviado desde la pantalla de diagnóstico.</p><p>Si lo recibes, el envío de correo de este entorno funciona.</p>');

        try {
            $this->mailer->send($message);
            $this->addFlash('success', sprintf('Email de prueba enviado a %s. Revisa la bandeja (o Mailpit/Mailtrap según el entorno).', $to));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', sprintf('No se pudo enviar: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('settings_diagnostics');
    }

    /**
     * Lanza uno de los disparadores de cron de la whitelist y guarda su salida
     * para mostrarla tras el redirect. En modo real (sin dry-run) el resumen a
     * admin se dirige al email del admin logueado.
     */
    #[Route('/cron', name: 'settings_diagnostics_cron', methods: ['POST'])]
    public function cron(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('diagnostics_cron', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_diagnostics');
        }

        $key = (string) $request->request->get('which');
        $command = self::COMMANDS[$key] ?? null;
        if ($command === null) {
            $this->addFlash('warning', 'Disparador desconocido.');
            return $this->redirectToRoute('settings_diagnostics');
        }

        $dryRun = $request->request->getBoolean('dry_run');

        $options = [];
        if ($dryRun) {
            $options['--dry-run'] = true;
        }
        // El resumen a admin exige destinatario en envío real; lo dirigimos a
        // quien está lanzando la prueba.
        if ($key === 'summary' && !$dryRun) {
            $adminEmail = $this->currentUserEmail();
            if ($adminEmail === '') {
                $this->addFlash('warning', 'Tu cuenta no tiene email; el resumen real necesita un destinatario. Prueba en modo simulación.');
                return $this->redirectToRoute('settings_diagnostics');
            }
            $options['--to'] = $adminEmail;
        }

        $run = $this->runCommand($command, $options);

        $request->getSession()->set('diagnostics_result', [
            'command' => $command . ($dryRun ? ' --dry-run' : ' (envío real)'),
            'exit' => $run['exit'],
            'output' => $run['output'],
        ]);

        return $this->redirectToRoute('settings_diagnostics');
    }

    /**
     * Ejecuta un comando de consola en proceso y captura su salida en texto
     * plano (sin ANSI), como la verías en una terminal.
     *
     * @param string                $command Nombre del comando (de la whitelist).
     * @param array<string, mixed>  $options Opciones para {@see ArrayInput}.
     * @return array{exit: int, output: string}
     */
    private function runCommand(string $command, array $options): array
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(['command' => $command] + $options);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        try {
            $exit = $application->run($input, $output);
            $text = $output->fetch();
        } catch (\Throwable $e) {
            $exit = 1;
            $text = sprintf("El comando lanzó una excepción:\n\n%s: %s", $e::class, $e->getMessage());
        }

        return ['exit' => $exit, 'output' => trim($text)];
    }

    /**
     * Email del usuario logueado, o cadena vacía si no tiene.
     */
    private function currentUserEmail(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? (string) $user->getEmail() : '';
    }
}
