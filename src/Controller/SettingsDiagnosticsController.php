<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AppSettings;
use App\Service\Cron\CronRunMode;
use App\Service\Cron\CronRunner;
use App\Service\Cron\CronTaskRegistry;
use App\Service\Email\EmailPreviewFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
     * Tareas que esta pantalla ofrece lanzar, por su clave en el manifiesto
     * {@see AppSettings::CRONS}. Antes había aquí un mapa propio de
     * identificador → comando, que era una segunda lista blanca a mantener a
     * mano; ahora la lista blanca es el manifiesto y esto sólo elige qué
     * subconjunto se enseña en el diagnóstico de envíos.
     */
    private const OFFERED_TASKS = [
        AppSettings::CRON_PICKUP_REMINDER,
        AppSettings::CRON_ADMIN_DELIVERY_SUMMARY,
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly EmailPreviewFactory $previewFactory,
        private readonly CronRunner $cronRunner,
        private readonly CronTaskRegistry $cronTasks,
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
            'reply_to' => $this->settings->getString(AppSettings::EMAIL_REPLY_TO),
            'result' => $result,
            'offered_tasks' => $this->offeredTasks(),
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
     * Guarda el Reply-To global ({@see AppSettings::EMAIL_REPLY_TO}): con valor,
     * las respuestas a los emails de la app irán ahí (el From sigue siendo
     * noreply@); vacío, se desactiva. Valida que sea una única dirección.
     */
    #[Route('/reply-to', name: 'settings_diagnostics_reply_to', methods: ['POST'])]
    public function saveReplyTo(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('diagnostics_reply_to', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_diagnostics');
        }

        $value = trim((string) $request->request->get('reply_to'));

        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('warning', sprintf('"%s" no es una dirección de email válida; no se ha guardado.', $value));
            return $this->redirectToRoute('settings_diagnostics');
        }

        $this->settings->setString(AppSettings::EMAIL_REPLY_TO, $value);

        $this->addFlash('success', $value === ''
            ? 'Reply-To desactivado: los emails no llevarán dirección de respuesta.'
            : sprintf('Reply-To activado: las respuestas irán a %s.', $value));

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
            $redirect = $this->settings->getString(AppSettings::EMAIL_REDIRECT_TO);
            $this->addFlash('success', $redirect === ''
                ? sprintf('Email de prueba enviado a %s. Revisa la bandeja (o Mailpit/Mailtrap según el entorno).', $to)
                : sprintf('Email de prueba para %s. La redirección de pruebas está activa: la entrega real fue a %s (revisa esa bandeja, o Mailpit/Mailtrap según el entorno).', $to, $redirect));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', sprintf('No se pudo enviar: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('settings_diagnostics');
    }

    /**
     * Lanza una de las tareas que ofrece la pantalla y guarda su salida para
     * mostrarla tras el redirect. La mecánica es la de {@see CronRunner}, la
     * misma que usa /gestion/settings.
     *
     * OJO a la diferencia con la otra pantalla, que es deliberada: allí el botón
     * SUSTITUYE al reloj caído y fuerza; aquí "Ejecutar" envía de verdad pero
     * respetando el interruptor de la tarea, porque el sentido de esta pantalla
     * es ver qué haría el cron. Los interruptores de email se respetan en ambas.
     */
    #[Route('/cron', name: 'settings_diagnostics_cron', methods: ['POST'])]
    public function cron(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('diagnostics_cron', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_diagnostics');
        }

        $key = (string) $request->request->get('which');
        if (!in_array($key, self::OFFERED_TASKS, true)) {
            $this->addFlash('warning', 'Disparador desconocido.');
            return $this->redirectToRoute('settings_diagnostics');
        }

        // Esta pantalla sirve para COMPROBAR qué haría el cron, así que ejecuta
        // como lo haría el reloj: si la tarea está pausada en /gestion/settings,
        // aquí tampoco corre. Es el comportamiento que ya tenía antes de existir
        // el runner compartido, y no debe cambiar por un refactor.
        $mode = $request->request->getBoolean('dry_run') ? CronRunMode::Preview : CronRunMode::AsScheduled;

        $result = $this->cronRunner->run($key, $mode, $this->currentUserEmail() ?: null);

        if ($result->blocked !== null) {
            $this->addFlash('warning', $result->blocked);
            return $this->redirectToRoute('settings_diagnostics');
        }

        $request->getSession()->set('diagnostics_result', [
            'command' => $result->command . ($result->isPreview() ? ' --dry-run' : ' (envío real)'),
            'exit' => $result->exitCode,
            'output' => $result->output,
            // En envío real, el destino que pinta el comando es el nominal (header To);
            // si la redirección de pruebas está activa, la entrega real fue aquí. Se lo
            // damos a la vista para que avise y no cunda el pánico al ver otro destinatario.
            'redirect_to' => $result->isPreview() ? '' : $this->settings->getString(AppSettings::EMAIL_REDIRECT_TO),
        ]);

        return $this->redirectToRoute('settings_diagnostics');
    }

    /**
     * Tareas que esta pantalla ofrece lanzar, resueltas desde el manifiesto
     * (clave, etiqueta y comando), para que la plantilla no repita ninguno de
     * los tres.
     *
     * @return list<array{key: string, label: string, command: string}>
     */
    private function offeredTasks(): array
    {
        return array_values(array_map(
            fn (string $key) => [
                'key' => $key,
                'label' => $this->cronTasks->label($key),
                'command' => AppSettings::CRONS[$key]['command'],
            ],
            self::OFFERED_TASKS
        ));
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
