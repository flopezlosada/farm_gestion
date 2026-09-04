<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;

/**
 * Lo que el calendario de turnos necesita saber de cada turno para pintarlo:
 * su estado, si le falta gente, si eso es un aviso o sólo un dato, y qué
 * turnos de un día se pueden juntar en una línea.
 *
 * UN TURNO TIENE UN ESTADO. El aviso de plazas es una capa encima, no un sexto
 * estado: por eso se devuelve aparte (`alarm`) y no como otro valor de `state`.
 *
 * LA REGLA DE COMPRIMIR NO ES «LO REPETIDO» SINO «LO QUE NO PIDE NADA». Los
 * turnos de una misma tarea en un día se juntan en una línea sólo si comparten
 * estado y ninguno pide algo (pasar lista, publicar, o gente). Si uno de ellos
 * pide algo, ése sale entero y sólo se comprime el resto. La compresión nunca
 * esconde trabajo.
 *
 * Lógica pura sobre entidades ya cargadas; lo que cambia con el modo (gestión o
 * socix) lo decide la plantilla, no esto.
 */
final class ShiftCalendar
{
    public const STATE_UPCOMING = 'porvenir';
    public const STATE_FULL = 'cubierto';
    public const STATE_TO_CLOSE = 'sincerrar';
    public const STATE_DONE = 'hecho';
    public const STATE_OFF = 'apagado';

    /** Un turno con gente por cubrir a menos de estos días es urgente. */
    public const URGENT_DAYS = 3;

    /** Cuántos turnos que piden algo enseña la barra de atención. */
    public const ATTENTION_CAP = 3;

    /** A partir de cuántos elementos en un día se pliega el resto. */
    public const VISIBLE_PER_DAY = 3;

    /**
     * El estado del turno para el calendario, derivado de su fase.
     *
     * @param VolunteerShift     $shift el turno
     * @param \DateTimeInterface $now   momento de referencia
     *
     * @return string una de las constantes STATE_*
     */
    public static function stateOf(VolunteerShift $shift, \DateTimeInterface $now): string
    {
        $phase = $shift->getPhase($now);

        if (\in_array($phase, [VolunteerShift::PHASE_DRAFT, VolunteerShift::PHASE_PAUSED, VolunteerShift::PHASE_CANCELLED], true)) {
            return self::STATE_OFF;
        }
        if (VolunteerShift::PHASE_TO_CLOSE === $phase || VolunteerShift::PHASE_TODAY === $phase) {
            // "Hoy" ya empezado es un turno al que hay que pasar lista.
            return null !== $shift->getStartsAt() && $shift->getStartsAt() <= $now
                ? self::STATE_TO_CLOSE
                : self::STATE_UPCOMING;
        }
        if (VolunteerShift::PHASE_CLOSED === $phase) {
            return self::STATE_DONE;
        }

        return null !== $shift->getSlots() && !$shift->hasRoom() ? self::STATE_FULL : self::STATE_UPCOMING;
    }

    /**
     * Cómo se pinta UN turno: estado, plazas que faltan, y si eso es aviso.
     *
     * @param VolunteerShift     $shift el turno
     * @param \DateTimeInterface $now   momento de referencia
     *
     * @return array{kind: 'shift', shift: VolunteerShift, state: string, missing: int, alarm: bool, asks: bool, urgent: bool}
     */
    public static function describe(VolunteerShift $shift, \DateTimeInterface $now): array
    {
        $state = self::stateOf($shift, $now);
        $missing = self::STATE_UPCOMING === $state ? (int) ($shift->getRemainingSlots() ?? 0) : 0;
        $routine = $shift->getOffer()?->isRoutine() ?? false;
        $alarm = !$routine && $missing > 0;

        $urgent = false;
        if ($alarm && null !== $shift->getStartsAt()) {
            $limit = \DateTimeImmutable::createFromInterface($now)->setTime(23, 59, 59)->modify(sprintf('+%d days', self::URGENT_DAYS));
            $urgent = $shift->getStartsAt() <= $limit;
        }

        return [
            'kind' => 'shift',
            'shift' => $shift,
            'state' => $state,
            'missing' => $missing,
            'alarm' => $alarm,
            'asks' => self::STATE_TO_CLOSE === $state || self::STATE_OFF === $state || $alarm,
            'urgent' => $urgent,
        ];
    }

    /**
     * Los turnos agrupados por día y, dentro del día, listos para pintar: lo
     * que pide algo primero, después por hora, y los repetidos de una misma
     * tarea que no piden nada juntos en un grupo.
     *
     * @param list<VolunteerShift> $shifts los turnos del rango
     * @param \DateTimeInterface   $now    momento de referencia
     *
     * @return array<string, list<array>> clave 'Y-m-d'; cada elemento es un `describe()` o un grupo
     *                                     `{kind: 'group', offer, shifts: list<array>, state, missing, routine}`
     */
    public static function days(array $shifts, \DateTimeInterface $now): array
    {
        $byDay = [];
        foreach ($shifts as $shift) {
            if (null === $shift->getStartsAt()) {
                continue;
            }
            $byDay[$shift->getStartsAt()->format('Y-m-d')][] = self::describe($shift, $now);
        }

        ksort($byDay);

        return array_map([self::class, 'groupDay'], $byDay);
    }

    /**
     * Los turnos que piden algo en todo el rango, para la barra de atención:
     * primero los que hay que cerrar, luego los urgentes por plazas.
     *
     * @param list<VolunteerShift> $shifts los turnos del rango
     * @param \DateTimeInterface   $now    momento de referencia
     *
     * @return array{items: list<array>, rest: int} los primeros ATTENTION_CAP y cuántos quedan fuera
     */
    public static function attention(array $shifts, \DateTimeInterface $now): array
    {
        $described = array_map(static fn (VolunteerShift $s): array => self::describe($s, $now), $shifts);
        $toClose = array_values(array_filter($described, static fn (array $i): bool => self::STATE_TO_CLOSE === $i['state']));
        $urgent = array_values(array_filter($described, static fn (array $i): bool => $i['urgent']));

        $byStart = static fn (array $a, array $b): int => $a['shift']->getStartsAt() <=> $b['shift']->getStartsAt();
        usort($toClose, $byStart);
        usort($urgent, $byStart);

        $all = [...$toClose, ...$urgent];

        return [
            'items' => \array_slice($all, 0, self::ATTENTION_CAP),
            'rest' => max(\count($all) - self::ATTENTION_CAP, 0),
        ];
    }

    /**
     * Qué estados aparecen en lo que se está viendo, para que la leyenda no
     * explique colores que no están en pantalla.
     *
     * @param array<string, list<array>> $days lo que devuelve days()
     *
     * @return list<string> constantes STATE_* presentes, sin repetir
     */
    public static function statesPresent(array $days): array
    {
        $states = [];
        foreach ($days as $items) {
            foreach ($items as $item) {
                $states[$item['state']] = true;
            }
        }

        return array_keys($states);
    }

    /**
     * Junta los turnos de una misma tarea que no piden nada y ordena el día.
     *
     * @param list<array> $items los `describe()` de un día
     *
     * @return list<array> turnos sueltos y grupos, ordenados
     */
    private static function groupDay(array $items): array
    {
        $byOffer = [];
        foreach ($items as $item) {
            $key = $item['shift']->getOffer()?->getId() ?? spl_object_id($item['shift']);
            $byOffer[$key][] = $item;
        }

        $out = [];
        foreach ($byOffer as $ofOffer) {
            // Lo que pide algo sale entero, siempre. Del resto, los que
            // comparten estado se juntan; un estado con un solo turno sale suelto.
            $quietByState = [];
            foreach ($ofOffer as $item) {
                if ($item['asks']) {
                    $out[] = $item;
                    continue;
                }
                $quietByState[$item['state']][] = $item;
            }

            foreach ($quietByState as $state => $quiet) {
                if (\count($quiet) < 2) {
                    $out[] = $quiet[0];
                    continue;
                }

                /** @var VolunteerOffer|null $offer */
                $offer = $quiet[0]['shift']->getOffer();
                usort($quiet, static fn (array $a, array $b): int => $a['shift']->getStartsAt() <=> $b['shift']->getStartsAt());
                $out[] = [
                    'kind' => 'group',
                    'offer' => $offer,
                    'shifts' => $quiet,
                    'state' => $state,
                    'missing' => array_sum(array_column($quiet, 'missing')),
                    'routine' => $offer?->isRoutine() ?? false,
                ];
            }
        }

        usort($out, static function (array $a, array $b): int {
            $askA = 'shift' === $a['kind'] && $a['asks'] ? 0 : 1;
            $askB = 'shift' === $b['kind'] && $b['asks'] ? 0 : 1;
            if ($askA !== $askB) {
                return $askA <=> $askB;
            }

            return self::firstStart($a) <=> self::firstStart($b);
        });

        return $out;
    }

    /**
     * La hora por la que se ordena un elemento del día: la suya, o la del
     * primer turno si es un grupo.
     *
     * @param array $item turno o grupo
     *
     * @return \DateTimeInterface|null la hora de inicio
     */
    private static function firstStart(array $item): ?\DateTimeInterface
    {
        return 'shift' === $item['kind']
            ? $item['shift']->getStartsAt()
            : $item['shifts'][0]['shift']->getStartsAt();
    }
}
