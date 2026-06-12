<?php

namespace App\Controller;

use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pantalla de configuración de la app: los toggles declarados en
 * {@see AppSettings::BOOLEANS}, agrupados. Sólo administración.
 */
#[Route('/gestion/configuracion')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    /**
     * Lista los ajustes con su valor efectivo (override o default), agrupados
     * tal y como los declara el catálogo.
     */
    #[Route('/', name: 'settings_index', methods: ['GET'])]
    public function index(AppSettings $settings): Response
    {
        return $this->render('settings/index.html.twig', [
            'groups' => $this->groupedSettings($settings),
        ]);
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
            $groups[$definition['group']][] = [
                'type' => 'bool',
                'name' => $name,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'value' => $settings->getBool($name),
            ];
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

        return $groups;
    }
}
