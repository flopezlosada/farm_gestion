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
     */
    public function tasks(): array
    {
        return AppSettings::CRONS;
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
