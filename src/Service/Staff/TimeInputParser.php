<?php

namespace App\Service\Staff;

/**
 * Parsea entradas de formulario (fecha + hora) a instantes en hora de Madrid,
 * el huso único del registro de jornada. Lógica pura, sin estado, compartida
 * por el panel del trabajador y el del supervisor (evita duplicar el parseo —y
 * su validación de rango— en los dos controllers).
 */
class TimeInputParser
{
    private const TZ = 'Europe/Madrid';

    /**
     * Compone fecha (Y-m-d) y hora (H:i) en un instante de Madrid, o null si no
     * son parseables. createFromFormat NO rechaza el desbordamiento (p. ej.
     * 2026-13-40 o 25:61 ruedan al día/mes siguiente y devuelven un objeto
     * válido), así que se valida con un round-trip: el instante parseado debe
     * re-formatearse exactamente igual que la entrada (ya zero-padded por los
     * inputs date/time del formulario).
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en formato H:i.
     * @return \DateTimeImmutable|null Instante en Madrid, o null si no es válido.
     */
    public function composeDateTime(string $date, string $time): ?\DateTimeImmutable
    {
        $input = trim($date) . ' ' . trim($time);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $input, new \DateTimeZone(self::TZ));

        return $dt !== false && $dt->format('Y-m-d H:i') === $input ? $dt : null;
    }

    /**
     * Aplica una hora (H:i, 00:00–23:59) sobre la fecha de un fichaje existente,
     * conservando su día. Rechaza horas fuera de rango (p. ej. 25:61, que
     * setTime desbordaría al día siguiente sin error).
     *
     * @param \DateTimeImmutable $base Fichaje cuya fecha se conserva.
     * @param string             $time Hora en formato H:i.
     * @return \DateTimeImmutable|null Nuevo instante, o null si la hora no es válida.
     */
    public function timeOnto(\DateTimeImmutable $base, string $time): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) !== 1
            || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return $base->setTime((int) $m[1], (int) $m[2]);
    }
}
