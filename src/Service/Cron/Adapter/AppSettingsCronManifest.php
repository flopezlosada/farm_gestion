<?php

namespace App\Service\Cron\Adapter;

use App\Service\AppSettings;
use App\Service\Cron\CronManifest;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * El manifiesto de ESTE proyecto: las tareas viven en las constantes de
 * {@see AppSettings} y los interruptores en la tabla `setting`.
 *
 * Es la pieza que cada aplicación reescribe al trasplantar el planificador, y la
 * única. Está en su propia carpeta `Adapter/` precisamente para que la frontera
 * se vea: lo de fuera se copia sin tocar, lo de aquí se sustituye.
 *
 * La decisión de que las cadencias vivan en una constante de PHP —y no en la
 * base de datos, editables desde la web— es de este proyecto, no del
 * planificador: sus horas tienen invariantes que no se ven (el congelado del
 * lunes va por delante del recordatorio) y quien usa la pantalla no tiene por
 * qué conocerlas. Otra aplicación puede implementar esta misma interfaz leyendo
 * de donde quiera sin que el núcleo se entere.
 */
#[AsAlias(CronManifest::class)]
class AppSettingsCronManifest implements CronManifest
{
    public function __construct(
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Excepción a las cadencias fijas: una tarea que declara `hour_setting` toma
     * su hora y minuto de ese ajuste de {@see AppSettings::TIMES}, editable desde
     * la pantalla de ajustes. Se resuelve aquí, y no en el núcleo, para que la
     * pantalla y el tick lean la misma hora sin que el planificador sepa de ajustes.
     */
    public function tasks(): array
    {
        return array_map(function (array $meta): array {
            if (!isset($meta['hour_setting'])) {
                return $meta;
            }
            [$hour, $minute] = array_map('intval', explode(':', $this->settings->getTime($meta['hour_setting'])));
            $meta['schedule'] = ['hour' => $hour, 'minute' => $minute] + $meta['schedule'];

            return $meta;
        }, AppSettings::CRONS);
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(string $settingKey): bool
    {
        return $this->settings->getBool($settingKey);
    }

    /**
     * {@inheritDoc}
     */
    public function label(string $settingKey): string
    {
        return AppSettings::BOOLEANS[$settingKey]['label'] ?? $settingKey;
    }

    /**
     * {@inheritDoc}
     *
     * La CSA reparte en hora peninsular y ahí vive su gente, así que las horas
     * del manifiesto son de Madrid. Coincide con lo que hoy tiene el hosting,
     * pero declararlo hace que deje de depender de ello.
     */
    public function timezone(): string
    {
        return 'Europe/Madrid';
    }
}
