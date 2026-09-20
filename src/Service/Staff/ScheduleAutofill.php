<?php

namespace App\Service\Staff;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkScheduleRepository;

/**
 * Rellena con el horario habitual los fichajes que le falten a un trabajador un
 * día concreto (el botón "rellenar con mi horario" del panel del trabajador,
 * pensado para cuando se le pasó la hora de entrar o salir y no fichó). No
 * inventa datos por su cuenta: se apoya en {@see WorkScheduleRepository} para
 * saber qué tramos tocan ese día y en {@see TimeEntryCorrector} para insertarlos,
 * heredando gratis la validación de {@see PunchSequenceGuard} — una hora que no
 * encaje con lo ya fichado se salta, no rompe el resto del relleno.
 *
 * Heurística simple a propósito (KISS): no reconcilia secuencias muy
 * descuadradas, sólo añade lo que falta de una jornada limpia. Para corregir un
 * día roto ya existe corregir/anular a mano.
 */
class ScheduleAutofill
{
    public function __construct(
        private readonly WorkScheduleRepository $schedules,
        private readonly HolidayRepository $holidays,
        private readonly AbsenceRepository $absences,
        private readonly TimeEntryRepository $timeEntries,
        private readonly TimeEntryCorrector $corrector,
    ) {
    }

    /**
     * ¿Tiene sentido ofrecer el botón de rellenar ese día? No, si no hay horario
     * aplicable con tramos ese día de la semana, si es festivo, o si el
     * trabajador tiene una ausencia aprobada que lo cubre.
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeImmutable $day    Día a comprobar (medianoche, hora de Madrid).
     * @return bool
     */
    public function isAvailableFor(Worker $worker, \DateTimeImmutable $day): bool
    {
        $schedule = $this->schedules->forWorkerAndDay($worker, (int) $day->format('N'));
        if ($schedule === null || $schedule->getTimes() === []) {
            return false;
        }

        if ($this->holidays->findNamesBetween($day, $day) !== []) {
            return false;
        }

        return $this->absences->findApprovedForWorkerBetween($worker, $day, $day) === [];
    }

    /**
     * Rellena los fichajes que falten ese día según el horario aplicable.
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeImmutable $day    Día a rellenar (medianoche, hora de Madrid).
     * @param User               $author Login que dispara el relleno (el propio trabajador).
     * @return TimeEntry[] Los fichajes creados (vacío si no había nada que añadir o nada encajaba).
     */
    public function fill(Worker $worker, \DateTimeImmutable $day, User $author): array
    {
        $schedule = $this->schedules->forWorkerAndDay($worker, (int) $day->format('N'));
        if ($schedule === null) {
            return [];
        }

        $dayEnd = $day->modify('+1 day');
        $pending = $this->timeEntries->findEffectiveForWorkerBetween($worker, $day, $dayEnd);

        $created = [];
        foreach ($this->boundaries($schedule->getTimes()) as [$type, $time]) {
            // Si el siguiente fichaje ya existente es del mismo tipo esperado, ese
            // borde ya está cubierto: se descarta de la cola sin insertar nada.
            $next = $pending[0] ?? null;
            if ($next !== null && $next->getType() === $type) {
                array_shift($pending);
                continue;
            }

            try {
                $created[] = $this->corrector->addEntry(
                    $worker,
                    $type,
                    $day->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2)),
                    $author,
                    TimeEntry::SOURCE_SELF,
                    'Horario habitual',
                );
            } catch (PunchSequenceException) {
                // Esa hora concreta no encaja con lo ya fichado (p. ej. un tramo
                // suelto fichado a mano). Se salta y se sigue con el resto.
            }
        }

        return $created;
    }

    /**
     * Aplana los tramos del horario a una secuencia cronológica de bordes
     * entrada/salida: [[TYPE_IN, "09:00"], [TYPE_OUT, "14:00"], ...].
     *
     * @param list<array{0: string, 1: string}> $times Tramos [inicio, fin].
     * @return list<array{0: string, 1: string}>
     */
    private function boundaries(array $times): array
    {
        $boundaries = [];
        foreach ($times as [$start, $end]) {
            $boundaries[] = [TimeEntry::TYPE_IN, $start];
            $boundaries[] = [TimeEntry::TYPE_OUT, $end];
        }

        return $boundaries;
    }
}
