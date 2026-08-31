<?php

namespace App\Tests\Service\Cron;

use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;

/**
 * Vigila la clasificación de las dos tareas del listado en el manifiesto.
 *
 * `needs_recipient` significa "esta tarea va a UNA persona concreta", y con él
 * {@see \App\Service\Cron\CronRunner} pasa `--to` con el correo de quien pulsa
 * "Ejecutar ahora" para no escribir a terceros desde una prueba manual.
 *
 * En estas dos tareas eso hacía daño de verdad: `--to` pisa los destinatarios de
 * cada punto, así que el listado le llegaba a quien pulsó el botón y —lo grave—
 * el apunte de idempotencia quedaba puesto, de modo que el envío real de esa
 * mañana ya no salía a nadie. Ninguna de las dos tiene un destinatario: una los
 * tiene por nodo y la otra escribe a cada socix.
 */
class DeliverySheetTaskManifestTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function tareasDelListado(): array
    {
        return [
            'listado al equipo' => [AppSettings::CRON_DELIVERY_SHEET],
            'confirmación al socix' => [AppSettings::CRON_DELIVERY_CONFIRMATION],
        ];
    }

    /**
     * @dataProvider tareasDelListado
     *
     * @param string $taskKey Clave de la tarea en el manifiesto.
     */
    public function testNoSeDirigenAQuienPulsaElBoton(string $taskKey): void
    {
        $this->assertFalse(
            AppSettings::CRONS[$taskKey]['needs_recipient'],
            sprintf(
                'Si "%s" declara needs_recipient, el botón "Ejecutar ahora" le mandará el listado a quien lo pulse '
                . 'y dejará el envío apuntado como hecho: el de esa mañana ya no saldría a sus destinatarios.',
                $taskKey,
            ),
        );
    }
}
