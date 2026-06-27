<?php

namespace App\Controller;

use App\Entity\Absence;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;
use App\Service\Staff\MonthGridBuilder;
use App\Service\Staff\PunchSequenceException;
use App\Service\Staff\TimeClock;
use App\Service\Staff\TimeEntryCorrector;
use App\Service\Staff\TimeInputParser;
use App\Service\Staff\WorkdayBuilder;
use App\Service\Staff\WorkingDayCounter;
use App\Service\Staff\YearCalendarBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Panel propio del trabajador (faceta laboral): fichar, su calendario de jornada
 * con corrección en autoservicio, y sus vacaciones. Espejo del panel del socix
 * ({@see PanelController}) pero sobre el registro horario. Requiere ROLE_WORKER,
 * derivado de tener un Worker vinculado al User.
 *
 * El trabajador corrige sus PROPIOS fichajes sin aprobación (modelo Factorial:
 * la traza append-only es la garantía); las vacaciones sí las aprueba el
 * supervisor.
 */
#[Route('/work')]
#[IsGranted('FEATURE_LABORAL')]
#[IsGranted('ROLE_WORKER')]
class WorkController extends AbstractController
{
    /**
     * Fichar: estado actual (dentro/fuera), botón de fichar y fichajes de hoy.
     *
     * @param TimeClock           $clock
     * @param TimeEntryRepository $timeEntries
     * @return Response
     */
    #[Route('', name: 'work', methods: ['GET'])]
    public function index(TimeClock $clock, TimeEntryRepository $timeEntries): Response
    {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        $worker = $this->worker();
        [$from, $to] = $this->dayWindow(new \DateTimeImmutable('today', $this->madrid()));

        return $this->render('work/index.html.twig', [
            'worker' => $worker,
            'is_open' => $clock->hasOpenInterval($worker),
            'today_entries' => $timeEntries->findEffectiveForWorkerBetween($worker, $from, $to),
        ]);
    }

    /**
     * Ficha el siguiente evento (entrada/salida según el estado).
     *
     * @param Request   $request
     * @param TimeClock $clock
     * @return Response
     */
    #[Route('/clock', name: 'work_clock', methods: ['POST'])]
    public function clock(Request $request, TimeClock $clock): Response
    {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('work_clock', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('work');
        }

        $worker = $this->worker();
        if (!$worker->isActive()) {
            $this->addFlash('error', 'Tu ficha laboral está inactiva: no puedes fichar. Contacta con la administración.');
            return $this->redirectToRoute('work');
        }

        $entry = $clock->clockNext($worker, $this->getUser());
        $this->addFlash('notice', $entry->getType() === TimeEntry::TYPE_IN
            ? 'Entrada fichada. ¡Buen trabajo!'
            : 'Salida fichada. ¡Hasta la próxima!');

        return $this->redirectToRoute('work');
    }

    /**
     * Mi calendario mensual de fichajes. Cada día enlaza a su detalle.
     *
     * @param Request             $request
     * @param TimeEntryRepository $timeEntries
     * @param AbsenceRepository   $absences
     * @param WorkdayBuilder      $workdays
     * @param MonthGridBuilder    $gridBuilder
     * @param HolidayRepository   $holidays
     * @return Response
     */
    #[Route('/calendar', name: 'work_calendar', methods: ['GET'])]
    public function calendar(
        Request $request,
        TimeEntryRepository $timeEntries,
        AbsenceRepository $absences,
        WorkdayBuilder $workdays,
        MonthGridBuilder $gridBuilder,
        HolidayRepository $holidays,
    ): Response {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        $worker = $this->worker();
        $madrid = $this->madrid();
        $today = new \DateTimeImmutable('today', $madrid);

        $year = (int) $request->query->get('year', $today->format('Y'));
        $month = (int) $request->query->get('month', $today->format('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) $today->format('n');
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $madrid);
        $monthEnd = $monthStart->modify('first day of next month');

        $entries = $timeEntries->findEffectiveForWorkerBetween($worker, $monthStart, $monthEnd);
        $totals = [];
        foreach ($workdays->buildDays($entries, $madrid) as $day) {
            $totals[$day['date']->format('Y-m-d')] = $day;
        }

        $covered = [];
        foreach ($absences->findApprovedForWorkerBetween($worker, $monthStart, $monthEnd) as $absence) {
            $cursor = $absence->getStartDate();
            while ($cursor <= $absence->getEndDate()) {
                $covered[$cursor->format('Y-m-d')] = $absence->getType();
                $cursor = $cursor->modify('+1 day');
            }
        }

        $holidayDates = $holidays->findNamesBetween($monthStart, $monthEnd->modify('-1 day'));

        return $this->render('work/calendar.html.twig', [
            'worker' => $worker,
            'weeks' => $gridBuilder->build($year, $month, $madrid, $totals, $covered, $today, $worker->getHireDate(), $holidayDates),
            'month_label' => $monthStart,
            'prev' => $monthStart->modify('-1 month'),
            'next' => $monthStart->modify('+1 month'),
        ]);
    }

    /**
     * Detalle de un día propio: fichajes de la jornada con corregir/anular/añadir.
     *
     * @param string              $date
     * @param TimeEntryRepository $timeEntries
     * @param WorkdayBuilder      $workdays
     * @return Response
     */
    #[Route('/day/{date}', name: 'work_day', methods: ['GET'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function day(string $date, TimeEntryRepository $timeEntries, WorkdayBuilder $workdays): Response
    {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        $madrid = $this->madrid();
        $dayStart = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $madrid);
        if ($dayStart === false) {
            throw $this->createNotFoundException('Fecha no válida.');
        }
        [$from, $to] = $this->dayWindow($dayStart);
        $days = $workdays->buildDays($timeEntries->findEffectiveForWorkerBetween($this->worker(), $from, $to), $madrid);

        return $this->render('work/day.html.twig', [
            'worker' => $this->worker(),
            'date' => $dayStart,
            'segments' => $days[0]['segments'] ?? [],
            'total' => $days[0]['totalMinutes'] ?? 0,
            'open' => $days[0]['open'] ?? false,
            'anomaly' => $days[0]['anomaly'] ?? false,
        ]);
    }

    /**
     * Mis vacaciones: saldo del año, lista de mis solicitudes y formulario para
     * pedir nuevas.
     *
     * @param AbsenceRepository   $absences
     * @param HolidayRepository   $holidays
     * @param YearCalendarBuilder $yearCalendar
     * @return Response
     */
    #[Route('/vacations', name: 'work_vacations', methods: ['GET'])]
    public function vacations(Request $request, AbsenceRepository $absences, HolidayRepository $holidays, YearCalendarBuilder $yearCalendar, WorkingDayCounter $workingDays): Response
    {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        $worker = $this->worker();
        $madrid = $this->madrid();
        // Año navegable: se puede mirar años pasados y el siguiente (pedir en
        // diciembre las vacaciones de Navidad del año que viene).
        $currentYear = (int) (new \DateTimeImmutable('today', $madrid))->format('Y');
        $year = (int) $request->query->get('year', (string) $currentYear);
        if ($year < $currentYear - 10 || $year > $currentYear + 1) {
            $year = $currentYear;
        }

        $mine = $worker->getAbsences()->toArray();
        usort($mine, static fn (Absence $a, Absence $b) => $b->getStartDate() <=> $a->getStartDate());

        // Festivos de una sola consulta que cubra el año Y todas las ausencias mostradas.
        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year), $madrid);
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year), $madrid);
        $minDate = $yearStart;
        $maxDate = $yearEnd;
        foreach ($mine as $absence) {
            $minDate = $absence->getStartDate() < $minDate ? $absence->getStartDate() : $minDate;
            $maxDate = $absence->getEndDate() > $maxDate ? $absence->getEndDate() : $maxDate;
        }
        $holidayDates = $holidays->findNamesBetween($minDate, $maxDate);

        // ¿Hay festivos cargados para el año que se mira? (típico: el año que viene
        // aún no los tiene; se avisa de que el cálculo no los descuenta todavía).
        $yearPrefix = sprintf('%d-', $year);
        $yearHasHolidays = false;
        foreach (array_keys($holidayDates) as $hDate) {
            if (str_starts_with($hDate, $yearPrefix)) {
                $yearHasHolidays = true;
                break;
            }
        }

        // Saldo: días LABORABLES de las vacaciones aprobadas del año (sin findes ni
        // festivos), recortados al año natural.
        $used = 0;
        foreach ($absences->findApprovedVacationsForWorkerInYear($worker, $year) as $vacation) {
            $used += $workingDays->count($vacation->getStartDate(), $vacation->getEndDate(), $holidayDates, $yearStart, $yearEnd);
        }

        // Días laborables de cada ausencia, para la etiqueta de la lista.
        $absenceWorkingDays = [];
        foreach ($mine as $absence) {
            $absenceWorkingDays[$absence->getId()] = $workingDays->count($absence->getStartDate(), $absence->getEndDate(), $holidayDates);
        }

        $absenceDays = [];
        foreach ($mine as $absence) {
            if (!in_array($absence->getStatus(), [Absence::STATUS_APPROVED, Absence::STATUS_REQUESTED], true)) {
                continue;
            }
            $cursor = $absence->getStartDate() > $yearStart ? $absence->getStartDate() : $yearStart;
            $end = $absence->getEndDate() < $yearEnd ? $absence->getEndDate() : $yearEnd;
            while ($cursor <= $end) {
                $key = $cursor->format('Y-m-d');
                // La aprobada manda sobre la pendiente si solapan. Se guarda tipo y
                // estado: el calendario colorea por tipo y matiza por estado.
                if (!isset($absenceDays[$key]) || $absence->getStatus() === Absence::STATUS_APPROVED) {
                    $absenceDays[$key] = ['type' => $absence->getType(), 'status' => $absence->getStatus()];
                }
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $this->render('work/vacations.html.twig', [
            'worker' => $worker,
            'year' => $year,
            'vacation_total' => $worker->getAnnualVacationDays(),
            'vacation_used' => $used,
            'my_absences' => $mine,
            'working_days' => $absenceWorkingDays,
            'current_year' => $currentYear,
            'min_year' => $absences->earliestStartYear($worker) ?? $currentYear,
            'year_has_holidays' => $yearHasHolidays,
            'today_ymd' => (new \DateTimeImmutable('today', $madrid))->format('Y-m-d'),
            'year_calendar' => $yearCalendar->build($year, $madrid, new \DateTimeImmutable('today', $madrid), $holidayDates, $absenceDays),
        ]);
    }

    /**
     * Solicita vacaciones (estado SOLICITADA, pendiente de aprobación).
     *
     * @param Request                $request
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/vacations/request', name: 'work_vacation_request', methods: ['POST'])]
    public function requestVacation(Request $request, EntityManagerInterface $em, AbsenceRepository $absences): Response
    {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('work_vacation', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('work_vacations');
        }

        $start = $this->parseDate((string) $request->request->get('start_date'));
        $end = $this->parseDate((string) $request->request->get('end_date'));
        if ($start === null || $end === null || $end < $start) {
            $this->addFlash('error', 'Revisa las fechas: indica un desde y un hasta válidos.');
            return $this->redirectToRoute('work_vacations');
        }

        if ($absences->findOverlappingForWorker($this->worker(), $start, $end) !== []) {
            $this->addFlash('error', 'Ya tienes una ausencia en esas fechas. Cancélala antes de pedir otra.');
            return $this->redirectToRoute('work_vacations');
        }

        // El trabajador puede pedir vacaciones, permiso o baja. Solo las vacaciones
        // descuentan saldo; baja y permiso quedan como ausencia justificada (la baja,
        // en rigor, se acredita con el parte médico: aquí solo se deja constancia).
        $type = (string) $request->request->get('type', Absence::TYPE_VACATION);
        if (!in_array($type, [Absence::TYPE_VACATION, Absence::TYPE_PERMIT, Absence::TYPE_SICK_LEAVE], true)) {
            $type = Absence::TYPE_VACATION;
        }

        // La baja médica no se aprueba (la determina el parte médico): se registra
        // directa. Vacaciones y permiso sí pasan por aprobación del supervisor.
        $status = $type === Absence::TYPE_SICK_LEAVE ? Absence::STATUS_APPROVED : Absence::STATUS_REQUESTED;

        $absence = (new Absence())
            ->setWorker($this->worker())
            ->setType($type)
            ->setStartDate($start)
            ->setEndDate($end)
            ->setStatus($status)
            ->setNote(trim((string) $request->request->get('note')) ?: null);

        $em->persist($absence);
        $em->flush();
        $this->addFlash('notice', $status === Absence::STATUS_APPROVED
            ? 'Baja registrada.'
            : 'Solicitud enviada. Te avisaremos cuando se apruebe.');

        return $this->redirectToRoute('work_vacations');
    }

    /**
     * Cancela una solicitud/vacación propia.
     *
     * @param Request                $request
     * @param Absence                $absence
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/vacations/{absenceId}/cancel', name: 'work_vacation_cancel', methods: ['POST'], requirements: ['absenceId' => '\d+'])]
    public function cancelVacation(
        Request $request,
        #[MapEntity(id: 'absenceId')] Absence $absence,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('work_vacation', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('work_vacations');
        }

        if ($absence->getWorker()?->getId() !== $this->worker()->getId()) {
            throw $this->createNotFoundException('Esa ausencia no es tuya.');
        }

        $today = new \DateTimeImmutable('today', $this->madrid());

        if ($absence->getEndDate() < $today) {
            // Ya terminó: cancelarla "desharía" días disfrutados. No se permite.
            $this->addFlash('error', 'Esa ausencia ya ha terminado; no se puede cancelar.');
        } elseif ($absence->getStartDate() > $today) {
            // Aún no ha empezado: se cancela entera.
            $absence->setStatus(Absence::STATUS_CANCELLED);
            $em->flush();
            $this->addFlash('notice', 'Solicitud cancelada.');
        } else {
            // En curso: se cancela A PARTIR DE MAÑANA. Los días ya disfrutados (hasta
            // hoy incluido) se conservan y siguen contando; se libera el resto.
            $absence->setEndDate($today);
            $em->flush();
            $this->addFlash('notice', sprintf(
                'Cancelada a partir de mañana. Se conservan los días hasta hoy (%s).',
                $today->format('d/m/Y'),
            ));
        }

        return $this->redirectToRoute('work_vacations');
    }

    /**
     * Añade un fichaje olvidado a una jornada propia.
     *
     * @param Request            $request
     * @param TimeEntryCorrector $corrector
     * @return Response
     */
    #[Route('/entry/add', name: 'work_entry_add', methods: ['POST'])]
    public function addEntry(Request $request, TimeEntryCorrector $corrector, TimeInputParser $timeInput): Response
    {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('work_entry', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('work');
        }

        $type = (string) $request->request->get('type');
        $occurredAt = $timeInput->composeDateTime((string) $request->request->get('date'), (string) $request->request->get('time'));
        if (!in_array($type, TimeEntry::TYPES, true) || $occurredAt === null) {
            $this->addFlash('error', 'Indica un tipo y una hora válidos.');
            return $this->redirectAfterEntry($request);
        }

        try {
            $corrector->addEntry($this->worker(), $type, $occurredAt, $this->getUser(), TimeEntry::SOURCE_SELF, $this->reasonOrDefault($request));
            $this->addFlash('success', 'Fichaje añadido.');
        } catch (PunchSequenceException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectAfterEntry($request);
    }

    /**
     * Corrige la hora de un fichaje propio.
     *
     * @param Request            $request
     * @param TimeEntry          $entry
     * @param TimeEntryCorrector $corrector
     * @return Response
     */
    #[Route('/entry/{entryId}/correct', name: 'work_entry_correct', methods: ['POST'], requirements: ['entryId' => '\d+'])]
    public function correctEntry(
        Request $request,
        #[MapEntity(id: 'entryId')] TimeEntry $entry,
        TimeEntryCorrector $corrector,
        TimeInputParser $timeInput,
    ): Response {
        if (($error = $this->guardOwnEntry($request, $entry)) !== null) {
            return $error;
        }

        $occurredAt = $timeInput->timeOnto($entry->getOccurredAt(), (string) $request->request->get('time'));
        if ($occurredAt === null) {
            $this->addFlash('error', 'Indica una hora válida.');
            return $this->redirectAfterEntry($request);
        }

        try {
            $corrector->correctTime($entry, $occurredAt, $this->getUser(), TimeEntry::SOURCE_SELF, $this->reasonOrDefault($request));
            $this->addFlash('success', 'Hora corregida.');
        } catch (PunchSequenceException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectAfterEntry($request);
    }

    /**
     * Anula un fichaje propio.
     *
     * @param Request            $request
     * @param TimeEntry          $entry
     * @param TimeEntryCorrector $corrector
     * @return Response
     */
    #[Route('/entry/{entryId}/void', name: 'work_entry_void', methods: ['POST'], requirements: ['entryId' => '\d+'])]
    public function voidEntry(
        Request $request,
        #[MapEntity(id: 'entryId')] TimeEntry $entry,
        TimeEntryCorrector $corrector,
    ): Response {
        if (($error = $this->guardOwnEntry($request, $entry)) !== null) {
            return $error;
        }

        $corrector->voidEntry($entry, $this->getUser(), $this->reasonOrDefault($request));
        $this->addFlash('success', 'Fichaje anulado.');

        return $this->redirectAfterEntry($request);
    }

    /**
     * Worker de la sesión.
     *
     * @return Worker
     */
    private function worker(): Worker
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->getWorker() instanceof Worker) {
            throw $this->createAccessDeniedException('Requiere ficha de trabajador.');
        }

        return $user->getWorker();
    }

    /**
     * @return \DateTimeZone Madrid.
     */
    private function madrid(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Madrid');
    }

    /**
     * Ventana [día, día+1) de un día de Madrid, para consultar fichajes. Todo se
     * almacena y compara en hora de Madrid (huso único), sin conversiones.
     *
     * @param \DateTimeImmutable $dayMadrid Medianoche del día en Madrid.
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function dayWindow(\DateTimeImmutable $dayMadrid): array
    {
        return [$dayMadrid, $dayMadrid->modify('+1 day')];
    }

    /**
     * Valida CSRF y que el fichaje sea del trabajador de la sesión y siga vigente.
     *
     * @param Request   $request
     * @param TimeEntry $entry
     * @return RedirectResponse|null
     */
    private function guardOwnEntry(Request $request, TimeEntry $entry): ?RedirectResponse
    {
        if (($redirect = $this->ensureWorker()) !== null) {
            return $redirect;
        }
        if (!$this->isCsrfTokenValid('work_entry', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectAfterEntry($request);
        }
        if ($entry->getWorker()?->getId() !== $this->worker()->getId()) {
            throw $this->createNotFoundException('Ese fichaje no es tuyo.');
        }
        if ($entry->isVoided()) {
            $this->addFlash('warning', 'Ese fichaje ya estaba anulado.');
            return $this->redirectAfterEntry($request);
        }

        return null;
    }

    /**
     * Redirección tras tocar un fichaje: al día si venía de ahí (return_to), o a Fichar.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    private function redirectAfterEntry(Request $request): RedirectResponse
    {
        $returnTo = (string) $request->request->get('return_to', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnTo) === 1) {
            return $this->redirectToRoute('work_day', ['date' => $returnTo]);
        }

        return $this->redirectToRoute('work');
    }

    /**
     * Convierte un input date (Y-m-d) en fecha, o null si no es válido.
     *
     * @param string $value
     * @return \DateTimeImmutable|null
     */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    /**
     * Motivo desde el request con texto por defecto.
     *
     * @param Request $request
     * @return string
     */
    private function reasonOrDefault(Request $request): string
    {
        $reason = trim((string) $request->request->get('reason', ''));

        return $reason !== '' ? $reason : 'Corrección del trabajador';
    }

    /**
     * El User debe tener un Worker vinculado.
     *
     * @return RedirectResponse|null
     */
    private function ensureWorker(): ?RedirectResponse
    {
        $user = $this->getUser();
        if ($user instanceof User && $user->getWorker() instanceof Worker) {
            return null;
        }

        $this->addFlash('error', 'Tu usuaria no está vinculada a una ficha de trabajador.');

        return $this->redirectToRoute($this->isGranted('ROLE_ADMIN') ? 'dashboard' : 'homepage');
    }
}
