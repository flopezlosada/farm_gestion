<?php

namespace App\Service\Staff;

use App\Entity\WorkSchedule;

/**
 * Los días de la semana en los que a un trabajador le toca trabajar, según su
 * horario ({@see \App\Entity\WorkSchedule}). Es lo que decide si un día sin
 * fichar es un HUECO (le tocaba y no hay nada) o su descanso: alguien a media
 * jornada de lunes a jueves no tiene un olvido cada viernes.
 *
 * Existe para que la regla viva en un solo sitio: el panel de huecos, el
 * calendario y el resumen del mes la comparten, y antes cada uno llevaba su
 * propio «lunes a viernes».
 */
final class WorkingWeek
{
    /**
     * @param array<int, true> $days Días ISO-8601 (1 lunes .. 7 domingo) laborables, como claves.
     */
    private function __construct(private readonly array $days)
    {
    }

    /**
     * @param int ...$days Días ISO-8601 (1 lunes .. 7 domingo) en los que se trabaja.
     * @return self
     *
     * @throws \InvalidArgumentException Si algún día no existe.
     */
    public static function of(int ...$days): self
    {
        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                throw new \InvalidArgumentException(sprintf('Día de la semana no válido: %d.', $day));
            }
        }

        return new self(array_fill_keys($days, true));
    }

    /**
     * La semana por defecto cuando no hay horario configurado: de lunes a viernes.
     *
     * @return self
     */
    public static function mondayToFriday(): self
    {
        return self::of(1, 2, 3, 4, 5);
    }

    /**
     * La semana de un trabajador a partir de sus filas de horario y las de la
     * plantilla global: cada día manda su excepción si la tiene (aunque esté
     * vacía: ese día no trabaja), si no la global; la misma regla que
     * {@see \App\Repository\WorkScheduleRepository::forWorkerAndDay()}. Un día
     * sin ninguna fila cae a lunes-viernes: mejor ver huecos de más que tragarse
     * olvidos por no haber rellenado la plantilla (una sola excepción de viernes
     * no puede dejar sin huecos el resto de la semana).
     *
     * Si un mismo día tiene dos filas del mismo nivel (la BD no lo impide, ver
     * {@see WorkSchedule}), gana la primera de la lista: quien llama la pasa
     * ordenada por id para que el resultado no dependa del orden de MySQL.
     *
     * @param WorkSchedule[] $schedules Filas del trabajador y globales (worker null).
     * @return self
     */
    public static function fromSchedules(array $schedules): self
    {
        $own = [];
        $global = [];
        foreach ($schedules as $schedule) {
            if ($schedule->getWorker() === null) {
                $global[$schedule->getDayOfWeek()] ??= $schedule->getTimes();
            } else {
                $own[$schedule->getDayOfWeek()] ??= $schedule->getTimes();
            }
        }

        $default = self::mondayToFriday();

        return self::of(...array_values(array_filter(
            range(1, 7),
            static fn (int $day) => isset($own[$day]) || isset($global[$day])
                ? ($own[$day] ?? $global[$day]) !== []
                : isset($default->days[$day]),
        )));
    }

    /**
     * ¿Le toca trabajar ese día?
     *
     * @param \DateTimeInterface $day Día.
     * @return bool
     */
    public function includes(\DateTimeInterface $day): bool
    {
        return isset($this->days[(int) $day->format('N')]);
    }
}
