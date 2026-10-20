<?php

namespace App\Service\Staff;

use App\Entity\User;
use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Repository\WorkerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El cierre mensual del registro de jornada: qué trabajadores tienen que
 * aprobarse cada mes, en qué estado está cada uno, y las dos operaciones
 * (aprobar y reabrir). La pantalla de cierre y el aviso a responsables leen de
 * aquí, para que "pendiente" signifique lo mismo en los dos sitios.
 *
 * Aprobar es responsabilidad de la ASOCIACIÓN, no del trabajador: no hay paso de
 * "conforme" del trabajador. El trabajador sigue viendo su registro.
 */
class WorkMonthClosing
{
    public function __construct(
        private readonly WorkerRepository $workers,
        private readonly MonthlyTimesheetReport $report,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Una fila por trabajador que tiene ese mes en su registro: dado de alta antes
     * de que acabara, y activo o con algo fichado ese mes (quien se fue a mitad de
     * mes también hay que cerrarlo).
     *
     * @param WorkMonth          $month Mes.
     * @param \DateTimeImmutable $today Hoy (Madrid), para no contar como hueco lo que aún no ha llegado.
     * @return list<array{worker: Worker, sheet: array, close: WorkMonthClose|null, issues: int}>
     *         `issues` = días con una jornada sin cerrar o descuadrada.
     */
    public function rowsFor(WorkMonth $month, \DateTimeImmutable $today): array
    {
        $tz = new \DateTimeZone('Europe/Madrid');
        $rows = [];

        foreach ($this->workers->findBy([], ['name' => 'ASC']) as $worker) {
            $hire = $worker->getHireDate();
            if ($hire === null || $hire->format('Y-m-d') >= $month->end()->format('Y-m-d')) {
                continue;
            }

            $sheet = $this->report->forMonth($worker, $month->year, $month->month, $tz, $today);
            if (!$worker->isActive() && $sheet['workedDays'] === 0) {
                continue;
            }

            $rows[] = [
                'worker' => $worker,
                'sheet' => $sheet,
                'close' => $sheet['close'],
                'issues' => \count(array_filter($sheet['rows'], static fn (array $r) => $r['open'] || $r['anomaly'])),
            ];
        }

        return $rows;
    }

    /**
     * Las filas de {@see self::rowsFor()} que siguen sin aprobar.
     *
     * @param WorkMonth          $month Mes.
     * @param \DateTimeImmutable $today Hoy (Madrid).
     * @return list<array{worker: Worker, sheet: array, close: null, issues: int}>
     */
    public function pendingFor(WorkMonth $month, \DateTimeImmutable $today): array
    {
        return array_values(array_filter($this->rowsFor($month, $today), static fn (array $row) => $row['close'] === null));
    }

    /**
     * Aprueba el mes de un trabajador. Desde ese momento el mes queda bloqueado
     * ({@see ClosedWorkMonths}).
     *
     * Sólo se aprueba un mes TERMINADO y sin jornadas a medias: una entrada sin
     * salida no suma horas, así que aprobarla dejaría un resumen que dice menos
     * de lo que se trabajó. Los días sin fichar sí se pueden aprobar: son lo que
     * hay, y quedan escritos como tales en el resumen.
     *
     * @param Worker             $worker Trabajador.
     * @param WorkMonth          $month  Mes.
     * @param User|null          $by     Quién aprueba.
     * @param \DateTimeImmutable $now    Ahora.
     * @return WorkMonthClose
     *
     * @throws \DomainException Si el mes no se puede aprobar; el mensaje se enseña tal cual.
     */
    public function approve(Worker $worker, WorkMonth $month, ?User $by, \DateTimeImmutable $now): WorkMonthClose
    {
        if (!$month->hasEndedAt($now)) {
            throw new \DomainException(sprintf('%s aún no ha terminado: se aprueba a partir del día 1 del mes siguiente.', ucfirst($month->label())));
        }

        $hire = $worker->getHireDate();
        if ($hire === null || $hire->format('Y-m-d') >= $month->end()->format('Y-m-d')) {
            throw new \DomainException(sprintf('%s no trabajaba aún en %s.', $worker->getName(), $month->label()));
        }

        $sheet = $this->report->forMonth($worker, $month->year, $month->month, new \DateTimeZone('Europe/Madrid'), $now);
        if ($sheet['close'] !== null) {
            throw new \DomainException(sprintf('%s de %s ya estaba aprobado.', ucfirst($month->label()), $worker->getName()));
        }

        $issues = \count(array_filter($sheet['rows'], static fn (array $r) => $r['open'] || $r['anomaly']));
        if ($issues > 0) {
            throw new \DomainException(sprintf(
                '%s tiene %d día%s con una jornada sin cerrar o descuadrada. Corrígelo antes de aprobar el mes.',
                $worker->getName(),
                $issues,
                $issues === 1 ? '' : 's',
            ));
        }

        $close = new WorkMonthClose($worker, $month, $by, $now);
        $this->em->persist($close);
        $this->em->flush();

        return $close;
    }

    /**
     * Reabre un mes aprobado para poder corregirlo. Queda la traza de quién y
     * cuándo, y habrá que volver a aprobarlo.
     *
     * @param WorkMonthClose     $close Aprobación vigente.
     * @param User|null          $by    Quién reabre.
     * @param \DateTimeImmutable $now   Ahora.
     * @return void
     *
     * @throws \LogicException Si ya estaba reabierta.
     */
    public function reopen(WorkMonthClose $close, ?User $by, \DateTimeImmutable $now): void
    {
        $close->reopen($by, $now);
        $this->em->flush();
    }
}
