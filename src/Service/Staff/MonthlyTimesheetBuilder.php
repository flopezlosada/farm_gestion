<?php

namespace App\Service\Staff;

/**
 * Construye la hoja mensual de jornada de un trabajador: una fila por cada día
 * natural del mes, con sus tramos trabajados, el total del día y una
 * clasificación (trabajado / fin de semana / festivo / ausencia / hueco /
 * anterior al alta / futuro). Es la base del PDF justificante que se entrega al
 * trabajador como ancla de integridad del registro fuera de la BBDD.
 *
 * Lógica pura y sin estado: recibe ya resueltos los días trabajados
 * ({@see WorkdayBuilder::buildDays()}), las ausencias aprobadas y los festivos,
 * y solo los coloca en el calendario del mes. Así es trivial de testear y no
 * depende de Doctrine.
 */
class MonthlyTimesheetBuilder
{
    /** El día es laborable y se trabajó (hay tramos). */
    public const STATUS_WORKED = 'worked';
    /** Fin de semana. */
    public const STATUS_WEEKEND = 'weekend';
    /** Festivo. */
    public const STATUS_HOLIDAY = 'holiday';
    /** Cubierto por una ausencia aprobada (vacaciones, baja, permiso…). */
    public const STATUS_ABSENCE = 'absence';
    /** Día laborable pasado sin fichaje ni ausencia: hueco en el registro. */
    public const STATUS_MISSING = 'missing';
    /** Anterior a la fecha de alta del trabajador (no hay registro legítimo). */
    public const STATUS_BEFORE_HIRE = 'before_hire';
    /** Posterior a hoy: aún no ha ocurrido. */
    public const STATUS_FUTURE = 'future';

    /**
     * Monta la hoja del mes indicado.
     *
     * @param int                     $year          Año.
     * @param int                     $month         Mes (1-12).
     * @param \DateTimeZone           $tz            Zona con la que se decide el día.
     * @param array<string, array{date: \DateTimeImmutable, segments: array, totalMinutes: int, open: bool, anomaly: bool}> $workedDays
     *        Días trabajados indexados por 'Y-m-d' (de {@see WorkdayBuilder::buildDays()}).
     * @param array<string, string>   $coveredAbsences Tipo de ausencia por día 'Y-m-d'.
     * @param array<string, string>   $holidayNames    Nombre del festivo por día 'Y-m-d'.
     * @param \DateTimeImmutable|null $hireDate      Fecha de alta (suelo del registro), o null.
     * @param \DateTimeImmutable      $today         Día de hoy (para marcar futuros).
     * @return array{
     *     rows: array<int, array{date: \DateTimeImmutable, dow: int, segments: array, totalMinutes: int, status: string, absenceType: string|null, holidayName: string|null, open: bool, anomaly: bool}>,
     *     totalMinutes: int,
     *     workedDays: int,
     *     missingDays: int
     * }
     */
    public function build(
        int $year,
        int $month,
        \DateTimeZone $tz,
        array $workedDays,
        array $coveredAbsences,
        array $holidayNames,
        ?\DateTimeImmutable $hireDate,
        \DateTimeImmutable $today,
    ): array {
        $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $monthEnd = $cursor->modify('first day of next month');
        $hireKey = $hireDate?->format('Y-m-d');
        $todayKey = $today->format('Y-m-d');

        $rows = [];
        $totalMinutes = 0;
        $workedCount = 0;
        $missingCount = 0;

        while ($cursor < $monthEnd) {
            $key = $cursor->format('Y-m-d');
            $dow = (int) $cursor->format('N'); // 1 (lunes) .. 7 (domingo)
            $worked = $workedDays[$key] ?? null;

            $status = $this->classify($key, $dow, $worked, $coveredAbsences, $holidayNames, $hireKey, $todayKey);

            $minutes = $worked['totalMinutes'] ?? 0;
            if ($status === self::STATUS_WORKED) {
                $totalMinutes += $minutes;
                $workedCount++;
            }
            if ($status === self::STATUS_MISSING) {
                $missingCount++;
            }

            $rows[] = [
                'date' => $cursor,
                'dow' => $dow,
                'segments' => $worked['segments'] ?? [],
                'totalMinutes' => $minutes,
                'status' => $status,
                'absenceType' => $coveredAbsences[$key] ?? null,
                'holidayName' => $holidayNames[$key] ?? null,
                'open' => $worked['open'] ?? false,
                'anomaly' => $worked['anomaly'] ?? false,
            ];

            $cursor = $cursor->modify('+1 day');
        }

        return [
            'rows' => $rows,
            'totalMinutes' => $totalMinutes,
            'workedDays' => $workedCount,
            'missingDays' => $missingCount,
        ];
    }

    /**
     * Clasifica un día del mes. El orden importa: lo que de verdad pasó (trabajo)
     * manda sobre el calendario; un fichaje en fin de semana o festivo cuenta como
     * trabajado, no se oculta.
     *
     * @param string                $key             Día 'Y-m-d'.
     * @param int                   $dow             Día de la semana (1-7).
     * @param array|null            $worked          Día trabajado, o null.
     * @param array<string, string> $coveredAbsences Ausencias por día.
     * @param array<string, string> $holidayNames    Festivos por día.
     * @param string|null           $hireKey         Fecha de alta 'Y-m-d', o null.
     * @param string                $todayKey        Hoy 'Y-m-d'.
     * @return string Una de las constantes STATUS_*.
     */
    private function classify(
        string $key,
        int $dow,
        ?array $worked,
        array $coveredAbsences,
        array $holidayNames,
        ?string $hireKey,
        string $todayKey,
    ): string {
        // Si se fichó, fue jornada trabajada, caiga en el día que caiga.
        if ($worked !== null && $worked['segments'] !== []) {
            return self::STATUS_WORKED;
        }

        if ($hireKey !== null && $key < $hireKey) {
            return self::STATUS_BEFORE_HIRE;
        }

        if (isset($coveredAbsences[$key])) {
            return self::STATUS_ABSENCE;
        }

        if (isset($holidayNames[$key])) {
            return self::STATUS_HOLIDAY;
        }

        if ($dow >= 6) {
            return self::STATUS_WEEKEND;
        }

        if ($key > $todayKey) {
            return self::STATUS_FUTURE;
        }

        return self::STATUS_MISSING;
    }
}
