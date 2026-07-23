<?php

namespace App\Controller;

use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pantalla de configuración de la app: los toggles declarados en
 * {@see AppSettings::BOOLEANS}, agrupados. Sólo administración.
 */
#[Route('/gestion/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    /**
     * Lista los ajustes con su valor efectivo (override o default), agrupados
     * tal y como los declara el catálogo.
     */
    #[Route('/', name: 'settings_index', methods: ['GET'])]
    public function index(Request $request, AppSettings $settings): Response
    {
        // La salida de la última ejecución manual de un cron se guarda en sesión
        // (no en flash: es multilínea) y se consume una sola vez al mostrarla.
        $cronOutput = $request->getSession()->remove('cron_last_output');

        return $this->render('settings/index.html.twig', [
            'groups' => $this->groupedSettings($settings),
            'cron_output' => $cronOutput,
        ]);
    }

    /**
     * Lanza un cron a mano, en proceso (sin depender de exec/proc_open, que el
     * hosting compartido puede tener deshabilitados): usa la API de consola de
     * Symfony y captura la salida para mostrársela a la administración.
     *
     * Sólo se puede lanzar lo declarado en {@see AppSettings::CRONS} (lista
     * blanca: el `cron` del POST se valida contra ese mapa). `mode=dry` añade
     * --dry-run; cualquier otro valor es ejecución real y pasa --force para
     * saltar el gate de la tarea programada (no los toggles de email, que se
     * respetan: con el email apagado, un envío manual tampoco sale).
     */
    #[Route('/cron/run', name: 'settings_cron_run', methods: ['POST'])]
    public function runCron(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('settings_cron_run', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_index');
        }

        $key = (string) $request->request->get('cron');
        $meta = AppSettings::CRONS[$key] ?? null;
        if ($meta === null) {
            $this->addFlash('warning', 'Tarea desconocida.');
            return $this->redirectToRoute('settings_index');
        }

        $dryRun = $request->request->get('mode') === 'dry';

        $args = ['command' => $meta['command']];
        if ($dryRun) {
            $args['--dry-run'] = true;
        } else {
            $args['--force'] = true;
        }

        // Estas tareas necesitan un destinatario en ejecución real (el supervisor o
        // admin): se lo mandamos a quien pulsa (el admin de la sesión). En dry-run no
        // hace falta (no envían nada). En el cron real el --to lo fija la línea del cron.
        $needsRecipient = [
            AppSettings::CRON_ADMIN_DELIVERY_SUMMARY,
            AppSettings::CRON_STAFF_GAPS_DIGEST,
            AppSettings::CRON_STAFF_OPEN_SHIFT_ALERT,
        ];
        if (in_array($key, $needsRecipient, true) && !$dryRun) {
            $adminEmail = $this->getUser()?->getEmail();
            if (!$adminEmail) {
                $this->addFlash('warning', 'Tu usuario no tiene email configurado; no se puede enviar. Usa la previsualización o configura tu email.');
                return $this->redirectToRoute('settings_index');
            }
            $args['--to'] = $adminEmail;
        }

        // Estos comandos son cortos (volúmenes pequeños), pero el envío por SMTP
        // puede tardar: levantamos el límite de tiempo para que no lo corte PHP.
        @set_time_limit(0);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($args), $output);

        $label = AppSettings::BOOLEANS[$key]['label'] ?? $meta['command'];
        $request->getSession()->set('cron_last_output', [
            'label' => $label . ($dryRun ? ' (previsualización)' : ''),
            'command' => $meta['command'],
            'output' => trim($output->fetch()),
            'ok' => $exitCode === 0,
        ]);
        $this->addFlash(
            $exitCode === 0 ? 'success' : 'error',
            sprintf('Tarea «%s» ejecutada (código %d). Salida abajo.', $label, $exitCode)
        );

        return $this->redirectToRoute('settings_index');
    }

    /**
     * Guarda toda la configuración de golpe. Los checkboxes no marcados no
     * viajan en el POST, así que cada booleano se persiste como "presente en
     * settings[] = encendido, ausente = apagado". Los enteros viajan siempre
     * (input number) y AppSettings los recorta a su rango al guardarlos.
     */
    #[Route('/', name: 'settings_save', methods: ['POST'])]
    public function save(Request $request, AppSettings $settings): Response
    {
        if (!$this->isCsrfTokenValid('settings_save', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_index');
        }

        $submitted = $request->request->all('settings');

        foreach (array_keys(AppSettings::BOOLEANS) as $name) {
            $settings->setBool($name, array_key_exists($name, $submitted));
        }
        foreach (array_keys(AppSettings::INTEGERS) as $name) {
            if (array_key_exists($name, $submitted)) {
                $settings->setInt($name, (int) $submitted[$name]);
            }
        }
        // La hora viaja como dos campos: settings[clave][h] y [m]. Se recortan
        // al rango y se normalizan a "HH:MM" con dos dígitos, así da igual que
        // lleguen "9", "99" o vacío: nunca sale un valor corrupto.
        foreach (array_keys(AppSettings::TIMES) as $name) {
            $parts = $submitted[$name] ?? null;
            if (is_array($parts) && isset($parts['h'], $parts['m'])) {
                $hour = max(0, min(23, (int) $parts['h']));
                $minute = max(0, min(59, (int) $parts['m']));
                $settings->setTime($name, sprintf('%02d:%02d', $hour, $minute));
            }
        }
        // Solo los STRINGS marcados 'general' se editan aquí; el resto (redirección de pruebas,
        // reply-to) viven en la pantalla de diagnóstico de envíos y no viajan en este form.
        foreach (AppSettings::STRINGS as $name => $definition) {
            if (($definition['general'] ?? false) && array_key_exists($name, $submitted)) {
                $settings->setString($name, (string) $submitted[$name]);
            }
        }

        $this->addFlash('success', 'Configuración guardada.');
        return $this->redirectToRoute('settings_index');
    }

    /**
     * Reorganiza el catálogo por grupo para la plantilla, resolviendo el valor
     * efectivo de cada ajuste. Cada item lleva su `type` (bool|int) para que la
     * plantilla pinte un checkbox o un campo numérico; los enteros añaden
     * `min`/`max`. El orden de grupos sigue al de los catálogos (booleanos
     * primero, luego enteros) preservando el orden de inserción de PHP.
     *
     * @return array<string, list<array{type: string, name: string, label: string, help: string, value: bool|int|string, min?: int, max?: int, unit?: string}>>
     */
    private function groupedSettings(AppSettings $settings): array
    {
        $groups = [];

        foreach (AppSettings::BOOLEANS as $name => $definition) {
            $item = [
                'type' => 'bool',
                'name' => $name,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'value' => $settings->getBool($name),
            ];
            // Los toggles de cron llevan además su comando para el botón
            // "Ejecutar ahora" (y si piden confirmación / ofrecen dry-run).
            if (isset(AppSettings::CRONS[$name])) {
                $item['command'] = AppSettings::CRONS[$name]['command'];
                $item['confirm'] = AppSettings::CRONS[$name]['confirm'];
                $item['dry'] = AppSettings::CRONS[$name]['dry'];
            }
            $groups[$definition['group']][] = $item;
        }

        foreach (AppSettings::INTEGERS as $name => $definition) {
            $groups[$definition['group']][] = [
                'type' => 'int',
                'name' => $name,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'value' => $settings->getInt($name),
                'min' => $definition['min'],
                'max' => $definition['max'],
                'unit' => $definition['unit'],
            ];
        }

        foreach (AppSettings::TIMES as $name => $definition) {
            $groups[$definition['group']][] = [
                'type' => 'time',
                'name' => $name,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'value' => $settings->getTime($name),
            ];
        }

        // Solo los STRINGS marcados 'general' (p.ej. el destinatario del resumen a admin); el
        // resto viven en pantallas específicas (diagnóstico de envíos).
        foreach (AppSettings::STRINGS as $name => $definition) {
            if (!($definition['general'] ?? false)) {
                continue;
            }
            $groups[$definition['group']][] = [
                'type' => 'string',
                'name' => $name,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'value' => $settings->getString($name),
            ];
        }

        return $groups;
    }
}
