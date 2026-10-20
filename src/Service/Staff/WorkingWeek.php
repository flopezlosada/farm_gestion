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
     * {@see \App\Repository\WorkScheduleRepository::forWorkerAndDay()}. Sin
     * ninguna fila, de lunes a viernes: mejor ver huecos de más que tragarse
     * olvidos por no haber rellenado la plantilla.
     *
     * @param WorkSchedule[] $schedules Filas del trabajador y globales (worker null), en cualquier orden.
     * @return self
     */
    public static function fromSchedules(array $schedules): self
    {
        if ($schedules === []) {
            return self::mondayToFriday();
        }

        $timesByDay = [];
        foreach ($schedules as $schedule) {
            if ($schedule->getWorker() === null && isset($timesByDay[$schedule->getDayOfWeek()])) {
                continue; // ya hay excepción del trabajador para ese día
            }
            $timesByDay[$schedule->getDayOfWeek()] = $schedule->getTimes();
        }

        return self::of(...array_keys(array_filter($timesByDay, static fn (array $times) => $times !== [])));
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
