<?php

namespace App\Service\Staff;

use App\Entity\Absence;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkerRepository;

/**
 * El registro de jornada de todo el equipo en un periodo, en los tres formatos
 * que se entregan a la Inspección de Trabajo cuando lo pide (art. 34.9 ET):
 *
 * - PDF: la misma página mensual que recibe cada trabajador, una por trabajador
 *   y mes, para leerlo.
 * - CSV diario: un renglón por tramo de jornada (o por día sin tramos), para
 *   cuadrar horas en una hoja de cálculo.
 * - CSV de traza: cada fichaje tal cual, anulados incluidos, con cuándo se
 *   apuntó y quién. Es lo que demuestra que el registro no se ha retocado sin
 *   dejar rastro.
 *
 * Los CSV van con «;» y BOM: es lo que abre por columnas un Excel en castellano,
 * y la coma partiría las observaciones.
 */
class StaffRecordExport
{
    private const TZ = 'Europe/Madrid';

    public function __construct(
        private readonly WorkerRepository $workers,
        private readonly TimeEntryRepository $timeEntries,
        private readonly MonthlyTimesheetReport $report,
        private readonly TimesheetPdfRenderer $renderer,
    ) {
    }

    /**
     * El PDF del periodo: portada y una página por trabajador y mes.
     *
     * Sólo de un año natural como mucho. dompdf monta todo el documento en
     * memoria, unos 2 MB por página: con dos trabajadores, un año son 80 MB y
     * cuatro años 240, por encima de los 192 MB de PHP en el hosting. Un periodo
     * largo se descarga año a año ({@see WorkPeriod::byYear()}).
     *
     * @param WorkPeriod         $period Los meses, dentro de un mismo año.
     * @param \DateTimeImmutable $now    Ahora (para los días por venir y el sello).
     * @return string Bytes del PDF.
     *
     * @throws \InvalidArgumentException Si el periodo pasa de un año natural.
     */
    public function pdf(WorkPeriod $period, \DateTimeImmutable $now): string
    {
        if (!$period->isWithinOneYear()) {
            throw new \InvalidArgumentException('El PDF se descarga año a año: elige un periodo dentro de un mismo año.');
        }

        $workers = $this->workers->findForRecordBetween($period->start(), $period->end());

        return $this->renderer->renderRecord($workers, $this->pages($workers, $period, $now), $period, $now);
    }

    /**
     * El CSV diario: un renglón por tramo trabajado, y uno por cada día sin
     * tramos con su situación (festivo, ausencia, sin fichar…). Quedan fuera los
     * días anteriores al alta, hoy (aún se puede fichar) y los que están por venir.
     *
     * @param WorkPeriod         $period Los meses.
     * @param \DateTimeImmutable $now    Ahora.
     * @return string El CSV.
     */
    public function dailyCsv(WorkPeriod $period, \DateTimeImmutable $now): string
    {
        $workers = $this->workers->findForRecordBetween($period->start(), $period->end());

        $todayKey = $now->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');

        $rows = [];
        foreach ($this->pages($workers, $period, $now) as $page) {
            $approved = $page['sheet']['close']?->getApprovedAt()->setTimezone(new \DateTimeZone(self::TZ))->format('d/m/Y');
            foreach ($page['sheet']['rows'] as $day) {
                // Lo que aún no ha pasado, por fecha y no por situación (un sábado
                // por venir está clasificado como fin de semana); hoy, si ya
                // tiene tramos, sí entra, como en el PDF.
                if (in_array($day['status'], [MonthlyTimesheetBuilder::STATUS_BEFORE_HIRE, MonthlyTimesheetBuilder::STATUS_BEFORE_RECORDING], true) || ([] === $day['segments'] && $day['date']->format('Y-m-d') >= $todayKey)) {
                    continue;
                }
                $common = [$page['worker']->getName(), $day['date']->format('d/m/Y')];
                $worked = MonthlyTimesheetBuilder::STATUS_WORKED === $day['status'];
                $tail = [$this->hm($worked ? $day['totalMinutes'] : null), MonthlyTimesheetBuilder::STATUS_LABELS[$day['status']], $this->observations($day), $approved ?? ''];

                $segments = $day['segments'] ?: [['in' => null, 'out' => null, 'minutes' => null]];
                foreach ($segments as $segment) {
                    $rows[] = [...$common, $this->time($segment['in']), $this->time($segment['out']), $this->hm($segment['minutes']), ...$tail];
                }
            }
        }

        return $this->csv(['Trabajador/a', 'Fecha', 'Entrada', 'Salida', 'Horas del tramo', 'Horas del día', 'Situación', 'Observaciones', 'Mes aprobado el'], $rows);
    }

    /**
     * El CSV de traza: cada fichaje del periodo, vigente o anulado, con la hora
     * que cuenta, cuándo se apuntó, quién y, si se anuló, quién, cuándo y por qué.
     *
     * @param WorkPeriod $period Los meses.
     * @return string El CSV.
     */
    public function trailCsv(WorkPeriod $period): string
    {
        $rows = array_map(fn (TimeEntry $entry): array => [
            $entry->getWorker()?->getName() ?? '(trabajador/a borrado)',
            $entry->isIn() ? 'Entrada' : 'Salida',
            $this->dateTime($entry->getOccurredAt()),
            $this->dateTime($entry->getRecordedAt()),
            TimeEntry::SOURCE_SUPERVISOR === $entry->getSource() ? 'Apuntado por gestión' : 'Fichado por el trabajador/a',
            $this->who($entry->getAuthor()),
            $entry->isDeferred() ? 'Sí' : 'No',
            $entry->getNote() ?? '',
            $this->dateTime($entry->getVoidedAt()),
            $this->who($entry->getVoidedBy()),
            $entry->getVoidReason() ?? '',
        ], $this->timeEntries->findAllBetween($period->start(), $period->end()));

        return $this->csv(['Trabajador/a', 'Tipo', 'Hora del fichaje', 'Apuntado el', 'Origen', 'Apuntado por', 'Diferido', 'Nota', 'Anulado el', 'Anulado por', 'Motivo de la anulación'], $rows);
    }

    /**
     * Nombre de fichero de cada formato ('registro-jornada-2026-01_2026-10-diario.csv').
     *
     * @param WorkPeriod $period Los meses.
     * @param string     $suffix '' para el PDF, '-diario' o '-traza' para los CSV.
     * @param string     $ext    'pdf' o 'csv'.
     * @return string
     */
    public function filename(WorkPeriod $period, string $suffix, string $ext): string
    {
        return sprintf('registro-jornada-%s%s.%s', $period->key(), $suffix, $ext);
    }

    /**
     * La hoja de cada trabajador y mes, en orden de trabajador y luego de mes.
     *
     * @param Worker[]           $workers Quién entra en el periodo.
     * @param WorkPeriod         $period  Los meses.
     * @param \DateTimeImmutable $now     Ahora.
     * @return list<array{worker: Worker, month: WorkMonth, sheet: array}>
     */
    private function pages(array $workers, WorkPeriod $period, \DateTimeImmutable $now): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = $now->setTimezone($tz)->setTime(0, 0);

        $pages = [];
        foreach ($workers as $worker) {
            $sheets = $this->report->forRange($worker, $period, $tz, $today);
            $until = $this->lastRecordedMonth($worker, $period);
            foreach ($period->months() as $month) {
                if (null !== $until && $month->start() > $until->start()) {
                    break;
                }
                $pages[] = ['worker' => $worker, 'month' => $month, 'sheet' => $sheets[$month->key()]];
            }
        }

        return $pages;
    }

    /**
     * Hasta qué mes hay registro de un trabajador ya inactivo: el de su último
     * fichaje del periodo. Sin fecha de baja en Worker, más allá no se sabe si
     * aún trabajaba, y sus meses saldrían enteros como «Sin fichar» ante la
     * Inspección. Para un activo, null: todo el periodo.
     *
     * @param Worker     $worker El trabajador.
     * @param WorkPeriod $period Los meses.
     * @return WorkMonth|null Último mes con registro, o null si no hay que cortar.
     */
    private function lastRecordedMonth(Worker $worker, WorkPeriod $period): ?WorkMonth
    {
        if ($worker->isActive()) {
            return null;
        }
        $last = $this->timeEntries->findLastEffectiveForWorkerBetween($worker, $period->start(), $period->end());

        return null === $last ? null : WorkMonth::containing($last->getOccurredAt());
    }

    /**
     * Lo que el PDF dice del día además de la situación: tipo de ausencia o
     * festivo, jornada sin cerrar o descuadrada, y si hay algún registro diferido.
     *
     * @param array $day Fila de {@see MonthlyTimesheetBuilder::build()}.
     * @return string
     */
    private function observations(array $day): string
    {
        return implode(' · ', array_filter([
            null !== $day['absenceType'] ? (Absence::TYPE_LABELS[$day['absenceType']] ?? 'Ausencia') : null,
            $day['holidayName'],
            $day['open'] ? 'Jornada sin cerrar' : null,
            $day['anomaly'] ? 'Salida sin entrada: revisar' : null,
            $day['deferred'] ? 'Registro diferido' : null,
        ]));
    }

    /**
     * La hora de un fichaje en Madrid, o vacío si el tramo no lo tiene.
     *
     * @param TimeEntry|null $entry El fichaje.
     * @return string 'HH:MM' o ''.
     */
    private function time(?TimeEntry $entry): string
    {
        return $entry?->getOccurredAt()?->setTimezone(new \DateTimeZone(self::TZ))->format('H:i') ?? '';
    }

    /**
     * Fecha y hora en Madrid, o vacío.
     *
     * @param \DateTimeImmutable|null $at El instante.
     * @return string 'dd/mm/aaaa HH:MM:SS' o ''.
     */
    private function dateTime(?\DateTimeImmutable $at): string
    {
        return $at?->setTimezone(new \DateTimeZone(self::TZ))->format('d/m/Y H:i:s') ?? '';
    }

    /**
     * Quién: el nombre con el que se ve en la app, o vacío.
     *
     * @param User|null $user La cuenta.
     * @return string
     */
    private function who(?User $user): string
    {
        return $user?->getDisplayName() ?? '';
    }

    /**
     * Minutos como horas y minutos ('7:30'). Cero es '0:00' (un tramo cerrado en
     * el mismo minuto); vacío sólo si no hay qué contar (tramo sin cerrar, día
     * sin trabajar).
     *
     * @param int|null $minutes Minutos, o null.
     * @return string
     */
    private function hm(?int $minutes): string
    {
        return null === $minutes ? '' : sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Escribe un CSV con «;» y BOM. Una celda que empieza por =, +, -, @, tabulador o retorno se
     * escapa con una comilla simple: la hoja de cálculo la tomaría por una
     * fórmula, y los nombres, notas y motivos los escribe una persona.
     *
     * @param string[]       $header Cabecera.
     * @param list<string[]> $rows   Renglones.
     * @return string
     */
    private function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ([$header, ...$rows] as $row) {
            fputcsv($out, array_map(static fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) ? "'" . $cell : $cell, $row), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
