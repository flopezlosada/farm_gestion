<?php

namespace App\Controller;

use App\Entity\CronRun;
use App\Entity\User;
use App\Repository\CronRunRepository;
use App\Service\AppSettings;
use App\Service\Cron\CronRunMode;
use App\Service\Cron\CronRunner;
use App\Service\Cron\CronTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pantalla de configuración de la app: los toggles declarados en
 * {@see AppSettings::BOOLEANS}, agrupados. Sólo administración.
 *
 * Las tareas programadas se pintan además con su última ejecución y su estado,
 * porque hasta agosto de 2026 esta pantalla mostraba interruptores pero no si
 * las tareas corrían: dos semanas sin ejecutarse ninguna se vieron igual que dos
 * semanas de normalidad.
 */
#[Route('/gestion/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly CronTaskRegistry $cronTasks,
        private readonly CronRunRepository $cronRuns,
    ) {
    }

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
     * Lanza un cron a mano. Toda la mecánica vive en {@see CronRunner}, que es
     * el mismo servicio que usa la pantalla de diagnóstico de envíos: antes cada
     * una tenía su copia del lanzador y su propia lista blanca.
     *
     * `mode=dry` previsualiza (--dry-run); cualquier otro valor es ejecución
     * real. Los interruptores de email se siguen respetando también en la
     * ejecución manual: con el envío apagado, aquí tampoco sale correo.
     */
    #[Route('/cron/run', name: 'settings_cron_run', methods: ['POST'])]
    public function runCron(Request $request, CronRunner $runner): Response
    {
        if (!$this->isCsrfTokenValid('settings_cron_run', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('settings_index');
        }

        $key = (string) $request->request->get('cron');
        if ($this->cronTasks->get($key) === null) {
            $this->addFlash('warning', 'Tarea desconocida.');
            return $this->redirectToRoute('settings_index');
        }

        // Esta pantalla es el SUSTITUTO del reloj mientras esté caído, así que su
        // ejecución real fuerza: congelar el listado un lunes tiene que funcionar
        // aunque la tarea programada esté pausada.
        $mode = $request->request->get('mode') === 'dry' ? CronRunMode::Preview : CronRunMode::Forced;
        $user = $this->getUser();

        $result = $runner->run($key, $mode, $user instanceof User ? $user->getEmail() : null);

        if ($result->blocked !== null) {
            $this->addFlash('warning', $result->blocked);
            return $this->redirectToRoute('settings_index');
        }

        $request->getSession()->set('cron_last_output', [
            'label' => $result->label . ($result->isPreview() ? ' (previsualización)' : ''),
            'command' => $result->command,
            'output' => $result->output,
            'ok' => $result->isSuccessful(),
        ]);
        $this->addFlash(
            $result->isSuccessful() ? 'success' : 'error',
            sprintf('Tarea «%s» ejecutada (código %d). Salida abajo.', $result->label, (int) $result->exitCode)
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
        // Una sola query para las últimas ejecuciones de las siete tareas.
        $lastRuns = $this->cronRuns->findLastRunPerTask();
        $now = new \DateTimeImmutable();

        foreach (AppSettings::BOOLEANS as $name => $definition) {
            $item = [
                'type' => 'bool',
                'name' => $name,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'value' => $settings->getBool($name),
            ];
            // Los toggles de cron llevan además su comando para el botón
            // "Ejecutar ahora" (y si piden confirmación / ofrecen dry-run), su
            // cadencia declarada y qué pasó la última vez que corrieron.
            if (isset(AppSettings::CRONS[$name])) {
                $item['command'] = AppSettings::CRONS[$name]['command'];
                $item['confirm'] = AppSettings::CRONS[$name]['confirm'];
                $item['dry'] = AppSettings::CRONS[$name]['dry'];
                $item['schedule'] = $this->cronTasks->describeSchedule($name);
                $item['unmet_dependencies'] = $this->cronTasks->unmetDependencies($name);
                $item['overdue'] = $this->cronTasks->isOverdue($name, $lastRuns[$name] ?? null, $now);

                $run = $lastRuns[$name] ?? null;
                $item['last_run'] = $run === null ? null : [
                    'status' => $run->getStatus(),
                    'at' => $run->getStartedAt(),
                    'age' => $this->cronTasks->describeAge($run->getStartedAt(), $now),
                    'manual' => $run->getTriggerSource() === CronRun::TRIGGER_MANUAL,
                    'detail' => $run->getDetail(),
                    'unfinished' => !$run->isFinished(),
                ];
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
