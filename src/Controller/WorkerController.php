<?php

namespace App\Controller;

use App\Entity\Absence;
use App\Entity\TimeEntry;
use App\Entity\Worker;
use App\Form\AbsenceType;
use App\Form\WorkerType;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkerRepository;
use App\Repository\WorkScheduleRepository;
use App\Security\WorkerAccessMailer;
use App\Security\WorkerUserProvisioner;
use App\Service\Staff\GapReport;
use App\Service\Staff\MonthGridBuilder;
use App\Service\Staff\MonthlyTimesheetReport;
use App\Service\Staff\ClosedWorkMonthException;
use App\Service\Staff\AbsenceNotifier;
use App\Service\Staff\ClosedWorkMonths;
use App\Service\Staff\PunchSequenceException;
use App\Service\Staff\TimesheetPdfRenderer;
use App\Service\Staff\WorkingDayCounter;
use App\Service\Staff\YearCalendarBuilder;
use App\Service\Staff\TimeEntryCorrector;
use App\Service\Staff\TimeInputParser;
use App\Service\Staff\WorkdayBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión de personal (módulo laboral): CRUD de trabajadores y su ficha con el
 * registro de jornada. La lectura la da ROLE_GESTION_LABORAL (clase); la
 * escritura (POST/PUT/PATCH/DELETE) la exige ROLE_GESTION_LABORAL_EDIT en
 * access_control, igual que el albergue, sin tocar cada action.
 */
#[Route('/gestion/staff')]
#[IsGranted('FEATURE_LABORAL')]
#[IsGranted('ROLE_GESTION_LABORAL')]
class WorkerController extends AbstractController
{
    /** Días hacia atrás que muestra el registro reciente de la ficha. */
    private const RECENT_DAYS = 14;

    /** Ventana del panel de huecos (días hacia atrás). */
    private const GAP_DAYS = 30;

    /**
     * Listado de trabajadores con un resumen (total y activos).
     *
     * @param WorkerRepository $workers
     * @return Response
     */
    #[Route('', name: 'staff_index', methods: ['GET'])]
    public function index(WorkerRepository $workers): Response
    {
        $all = $workers->findBy([], ['name' => 'ASC']);

        return $this->render('staff/index.html.twig', [
            'workers' => $all,
            'stats' => [
                'total' => count($all),
                'active' => count(array_filter($all, static fn (Worker $w) => $w->isActive())),
            ],
        ]);
    }

    /**
     * Panel de huecos: por cada trabajador activo, los días laborables recientes
     * sin fichaje y sin ausencia justificada. Es lo que el supervisor revisa para
     * que no se acumulen olvidos. No persiste nada: se calcula al vuelo.
     *
     * @param GapReport $gapReport
     * @return Response
     */
    #[Route('/gaps', name: 'staff_gaps', methods: ['GET'])]
    public function gaps(GapReport $gapReport): Response
    {
        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);
        $fromMadrid = $today->modify(sprintf('-%d days', self::GAP_DAYS));

        return $this->render('staff/gaps.html.twig', [
            'rows' => $gapReport->activeWorkersGaps($fromMadrid, $today),
            'days' => self::GAP_DAYS,
        ]);
    }

    /**
     * Calendario del EQUIPO: una fila por trabajador con sus ausencias (pedidas y
     * aprobadas) del mes, para ver de un vistazo solapes y planificar. Navega por
     * meses.
     *
     * @param Request           $request
     * @param WorkerRepository  $workers
     * @param AbsenceRepository $absences
     * @param HolidayRepository $holidays
     * @param TeamMonthBuilder  $builder
     * @return Response
     */
    #[Route('/calendar', name: 'staff_team_calendar', methods: ['GET'])]
    public function teamCalendar(Request $request, WorkerRepository $workers, AbsenceRepository $absences, HolidayRepository $holidays, YearCalendarBuilder $yearCalendar): Response
    {
        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);
        $currentYear = (int) $today->format('Y');
        $year = (int) $request->query->get('year', (string) $currentYear);
        if ($year < $currentYear - 10 || $year > $currentYear + 1) {
            $year = $currentYear;
        }

        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year), $madrid);
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year), $madrid);
        $holidayDates = $holidays->findNamesBetween($yearStart, $yearEnd);

        // Mismo grid de 12 mini-meses que el calendario del trabajador (sin ausencias
        // propias: los eventos del equipo se pintan encima por día).
        $grid = $yearCalendar->build($year, $madrid, $today, $holidayDates, []);

        $active = $workers->findBy(['active' => true], ['name' => 'ASC']);
        $teamWorkers = [];
        $byDay = [];
        foreach (array_values($active) as $index => $worker) {
            $teamWorkers[] = ['index' => $index, 'name' => $worker->getName()];
            foreach ($absences->findOverlappingForWorker($worker, $yearStart, $yearEnd) as $absence) {
                $cursor = $absence->getStartDate() > $yearStart ? $absence->getStartDate() : $yearStart;
                $end = $absence->getEndDate() < $yearEnd ? $absence->getEndDate() : $yearEnd;
                while ($cursor <= $end) {
                    $byDay[$cursor->format('Y-m-d')][] = [
                        'index' => $index,
                        'name' => $worker->getName(),
                        'type' => $absence->getType(),
                        'status' => $absence->getStatus(),
                    ];
                    $cursor = $cursor->modify('+1 day');
                }
            }
        }

        return $this->render('staff/team_calendar.html.twig', [
            'year' => $year,
            'current_year' => $currentYear,
            'min_year' => $absences->earliestStartYear() ?? $currentYear,
            'grid' => $grid,
            'by_day' => $byDay,
            'team_workers' => $teamWorkers,
        ]);
    }

    /**
     * Ficha del trabajador: sus datos, el saldo de vacaciones del año y el
     * registro de jornada reciente (últimos días), agrupado por día en hora de
     * Madrid.
     *
     * @param Worker              $worker
     * @param TimeEntryRepository $timeEntries
     * @param AbsenceRepository   $absences
     * @return Response
     */
    #[Route('/{id}', name: 'staff_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Worker $worker,
        TimeEntryRepository $timeEntries,
        AbsenceRepository $absences,
        WorkerUserProvisioner $provisioner,
        WorkdayBuilder $workdays,
        HolidayRepository $holidays,
        WorkingDayCounter $workingDays,
    ): Response {
        $madrid = new \DateTimeZone('Europe/Madrid');

        // Ventana [hoy-N, mañana) en días de Madrid (huso único, sin conversión).
        $startMadrid = (new \DateTimeImmutable('today', $madrid))->modify(sprintf('-%d days', self::RECENT_DAYS - 1));
        $to = new \DateTimeImmutable('tomorrow', $madrid);

        $entries = $timeEntries->findEffectiveForWorkerBetween($worker, $startMadrid, $to);

        // Reconstruye las jornadas (tramos + total) a partir de los fichajes.
        $days = $workdays->buildDays($entries, $madrid);

        $year = (int) (new \DateTimeImmutable('today', $madrid))->format('Y');
        $yearStart = new \DateTimeImmutable(sprintf('%d-01-01', $year), $madrid);
        $yearEnd = new \DateTimeImmutable(sprintf('%d-12-31', $year), $madrid);

        // Festivos de una sola consulta que cubra el año y las ausencias mostradas.
        $sorted = $this->sortedAbsences($worker);
        $minDate = $yearStart;
        $maxDate = $yearEnd;
        foreach ($sorted as $absence) {
            $minDate = $absence->getStartDate() < $minDate ? $absence->getStartDate() : $minDate;
            $maxDate = $absence->getEndDate() > $maxDate ? $absence->getEndDate() : $maxDate;
        }
        $holidayDates = $holidays->findNamesBetween($minDate, $maxDate);

        // Saldo en días LABORABLES (sin findes ni festivos), recortado al año.
        $usedVacationDays = 0;
        foreach ($absences->findApprovedVacationsForWorkerInYear($worker, $year) as $vacation) {
            $usedVacationDays += $workingDays->count($vacation->getStartDate(), $vacation->getEndDate(), $holidayDates, $yearStart, $yearEnd);
        }

        $absenceWorkingDays = [];
        foreach ($sorted as $absence) {
            $absenceWorkingDays[$absence->getId()] = $workingDays->count($absence->getStartDate(), $absence->getEndDate(), $holidayDates);
        }

        return $this->render('staff/show.html.twig', [
            'worker' => $worker,
            'days' => $days,
            'year' => $year,
            'vacation_used' => $usedVacationDays,
            'vacation_total' => $worker->getAnnualVacationDays(),
            'access_user' => $provisioner->userFor($worker),
            'absences' => $sorted,
            'working_days' => $absenceWorkingDays,
        ]);
    }

    /**
     * Ausencias del trabajador ordenadas de más reciente a más antigua (por
     * fecha de inicio), para pintarlas en la ficha.
     *
     * @param Worker $worker
     * @return Absence[]
     */
    private function sortedAbsences(Worker $worker): array
    {
        $absences = $worker->getAbsences()->toArray();
        usort($absences, static fn (Absence $a, Absence $b) => $b->getStartDate() <=> $a->getStartDate());

        return $absences;
    }

    /**
     * Registra una ausencia desde la administración: queda APROBADA directamente
     * (el supervisor es quien la concede).
     *
     * Es también la forma de JUSTIFICAR un día sin fichar (un permiso que se le
     * dio, una huelga…): desde el hueco se llega con `date` (el día, ya puesto en
     * el formulario) y `back` (a qué pantalla volver: `close`, `day` o `gaps`).
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param EntityManagerInterface $em
     * @param AbsenceRepository      $absences
     * @param ClosedWorkMonths       $closedMonths
     * @return Response
     */
    #[Route('/{id}/absence/new', name: 'staff_absence_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function newAbsence(Request $request, Worker $worker, EntityManagerInterface $em, AbsenceRepository $absences, ClosedWorkMonths $closedMonths): Response
    {
        // Ida y vuelta por el formato: createFromFormat acepta un 31 de febrero
        // y lo convierte en marzo sin avisar.
        $date = (string) $request->query->get('date', '');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: null;
        if ($day?->format('Y-m-d') !== $date) {
            $day = null;
        }
        $back = (string) $request->query->get('back', '');

        $absence = (new Absence())->setWorker($worker);
        if ($day !== null) {
            $absence->setStartDate($day)->setEndDate($day);
        }
        $form = $this->createForm(AbsenceType::class, $absence);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Se vuelve al mes/día de lo que se guardó, aunque se cambiaran las fechas.
            $day = $absence->getStartDate();
            if ($absences->findOverlappingForWorker($worker, $absence->getStartDate(), $absence->getEndDate()) !== []) {
                $this->addFlash('error', 'Ese trabajador ya tiene una ausencia en esas fechas.');

                return $this->redirectAfterAbsence($back, $worker, $day);
            }
            // Se registra ya aprobada, así que cambia el registro de esos meses.
            try {
                $closedMonths->assertRangeOpen($worker, $absence->getStartDate(), $absence->getEndDate());
            } catch (ClosedWorkMonthException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectAfterAbsence($back, $worker, $day);
            }
            $absence->setStatus(Absence::STATUS_APPROVED);
            $absence->setApprovedBy($this->getUser());
            $em->persist($absence);
            $em->flush();
            $this->addFlash('success', 'Ausencia registrada.');

            return $this->redirectAfterAbsence($back, $worker, $day);
        }

        return $this->render('staff/absence_new.html.twig', [
            'worker' => $worker,
            'form' => $form->createView(),
            'cancel_url' => $this->redirectAfterAbsence($back, $worker, $day)->getTargetUrl(),
        ]);
    }

    /**
     * A dónde se vuelve tras registrar una ausencia: a la pantalla desde la que
     * se fue a justificar el hueco, o a la ficha. `back` es una lista cerrada,
     * no una URL, para que nadie pueda usarlo de redirección abierta.
     *
     * @param string                  $back   `close`, `day`, `gaps` o cualquier otra cosa (ficha).
     * @param Worker                  $worker Trabajador.
     * @param \DateTimeImmutable|null $day    Día que se justificaba (para volver a su mes o a su día).
     * @return RedirectResponse
     */
    private function redirectAfterAbsence(string $back, Worker $worker, ?\DateTimeImmutable $day): RedirectResponse
    {
        return match (true) {
            $back === 'close' && $day !== null => $this->redirectToRoute('staff_month_close', ['year' => (int) $day->format('Y'), 'month' => (int) $day->format('n')]),
            $back === 'day' && $day !== null => $this->redirectToRoute('staff_day', ['id' => $worker->getId(), 'date' => $day->format('Y-m-d')]),
            $back === 'gaps' => $this->redirectToRoute('staff_gaps'),
            default => $this->redirectToRoute('staff_show', ['id' => $worker->getId()]),
        };
    }

    /**
     * Aprueba una solicitud de ausencia (la que pidió el trabajador).
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param Absence                $absence
     * @param EntityManagerInterface $em
     * @param ClosedWorkMonths       $closedMonths
     * @param AbsenceNotifier        $notifier
     * @return Response
     */
    #[Route('/{id}/absence/{absenceId}/approve', name: 'staff_absence_approve', methods: ['POST'], requirements: ['id' => '\d+', 'absenceId' => '\d+'])]
    public function approveAbsence(
        Request $request,
        Worker $worker,
        #[MapEntity(id: 'absenceId')] Absence $absence,
        EntityManagerInterface $em,
        ClosedWorkMonths $closedMonths,
        AbsenceNotifier $notifier,
    ): Response {
        // Aprobarla la mete en el registro de esos meses: si alguno ya está
        // aprobado, antes hay que reabrirlo.
        try {
            $closedMonths->assertRangeOpen($worker, $absence->getStartDate(), $absence->getEndDate());
        } catch (ClosedWorkMonthException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        return $this->changeAbsenceStatus($request, $worker, $absence, Absence::STATUS_APPROVED, 'Ausencia aprobada.', $em, $notifier);
    }

    /**
     * Rechaza una solicitud de ausencia.
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param Absence                $absence
     * @param EntityManagerInterface $em
     * @param AbsenceNotifier        $notifier
     * @return Response
     */
    #[Route('/{id}/absence/{absenceId}/reject', name: 'staff_absence_reject', methods: ['POST'], requirements: ['id' => '\d+', 'absenceId' => '\d+'])]
    public function rejectAbsence(
        Request $request,
        Worker $worker,
        #[MapEntity(id: 'absenceId')] Absence $absence,
        EntityManagerInterface $em,
        AbsenceNotifier $notifier,
    ): Response {
        return $this->changeAbsenceStatus($request, $worker, $absence, Absence::STATUS_REJECTED, 'Solicitud rechazada.', $em, $notifier);
    }

    /**
     * Cancela una ausencia (aprobada o solicitada). Aplica la MISMA regla que la
     * cancelación del trabajador ({@see \App\Controller\WorkController::cancelVacation}):
     * si ya terminó no se toca, si no ha empezado se cancela entera, y si está en
     * curso se TRUNCA a hoy conservando los días disfrutados. Antes el supervisor
     * la marcaba CANCELLED sin truncar, borrando los días ya disfrutados del
     * cómputo.
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param Absence                $absence
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/absence/{absenceId}/cancel', name: 'staff_absence_cancel', methods: ['POST'], requirements: ['id' => '\d+', 'absenceId' => '\d+'])]
    public function cancelAbsence(
        Request $request,
        Worker $worker,
        #[MapEntity(id: 'absenceId')] Absence $absence,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('staff_absence' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        if ($absence->getWorker()?->getId() !== $worker->getId()) {
            throw $this->createNotFoundException('La ausencia no pertenece a este trabajador.');
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));

        switch ($absence->cancelAsOf($today)) {
            case Absence::CANCEL_TOO_LATE:
                $this->addFlash('warning', 'Esa ausencia ya ha terminado; no se puede cancelar.');
                break;
            case Absence::CANCEL_FULL:
                // No se toca approvedBy: ese campo registra quién APROBÓ la
                // ausencia, no quién la cancela (cancelar no es aprobar).
                $em->flush();
                $this->addFlash('success', 'Ausencia cancelada.');
                break;
            case Absence::CANCEL_TRUNCATED:
                $em->flush();
                $this->addFlash('success', sprintf(
                    'Cancelada a partir de mañana. Se conservan los días hasta hoy (%s).',
                    $today->format('d/m/Y'),
                ));
                break;
        }

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Cambia el estado de una ausencia validando CSRF y pertenencia, y registra
     * quién decidió. Centraliza aprobar/rechazar/cancelar.
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param Absence                $absence
     * @param string                 $status   Nuevo estado.
     * @param string                 $message  Flash de éxito.
     * @param EntityManagerInterface $em
     * @param AbsenceNotifier        $notifier Avisa al trabajador de la decisión.
     * @return Response
     */
    private function changeAbsenceStatus(
        Request $request,
        Worker $worker,
        Absence $absence,
        string $status,
        string $message,
        EntityManagerInterface $em,
        AbsenceNotifier $notifier,
    ): Response {
        if (!$this->isCsrfTokenValid('staff_absence' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        if ($absence->getWorker()?->getId() !== $worker->getId()) {
            throw $this->createNotFoundException('La ausencia no pertenece a este trabajador.');
        }

        $absence->setStatus($status);
        $absence->setApprovedBy($this->getUser());
        $em->flush();
        $notifier->decided($absence);
        $this->addFlash('success', $message);

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Da acceso de login al trabajador por email (reusa cuenta existente o crea
     * una) y le envía la invitación para entrar con Google. La escritura la exige
     * ROLE_GESTION_LABORAL_EDIT en access_control.
     *
     * @param Request                 $request
     * @param Worker                  $worker
     * @param WorkerUserProvisioner   $provisioner
     * @param WorkerAccessMailer      $accessMailer
     * @return Response
     */
    #[Route('/{id}/access/grant', name: 'staff_access_grant', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function grantAccess(
        Request $request,
        Worker $worker,
        WorkerUserProvisioner $provisioner,
        WorkerAccessMailer $accessMailer,
    ): Response {
        if (!$this->isCsrfTokenValid('staff_access' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        try {
            $user = $provisioner->grantAccess($worker, (string) $request->request->get('email', ''));
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        if ($accessMailer->send($user)) {
            $this->addFlash('success', sprintf('Acceso concedido a %s. Le hemos enviado un correo para entrar con Google.', $user->getEmail()));
        } else {
            $this->addFlash('warning', sprintf('Acceso concedido a %s, pero no se pudo enviar la invitación. Reenvíala desde su ficha.', $user->getEmail()));
        }

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Reenvía la invitación para entrar a la cuenta del trabajador.
     *
     * @param Request               $request
     * @param Worker                $worker
     * @param WorkerUserProvisioner $provisioner
     * @param WorkerAccessMailer    $accessMailer
     * @return Response
     */
    #[Route('/{id}/access/resend', name: 'staff_access_resend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendAccess(
        Request $request,
        Worker $worker,
        WorkerUserProvisioner $provisioner,
        WorkerAccessMailer $accessMailer,
    ): Response {
        if (!$this->isCsrfTokenValid('staff_access' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        $user = $provisioner->userFor($worker);
        if ($user === null) {
            $this->addFlash('warning', 'Este trabajador aún no tiene cuenta de acceso. Dale acceso primero.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        if ($accessMailer->send($user)) {
            $this->addFlash('success', sprintf('Invitación reenviada a %s.', $user->getEmail()));
        } else {
            $this->addFlash('error', 'No se pudo enviar la invitación. Revisa la configuración de correo.');
        }

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Retira el acceso de login del trabajador (desvincula su cuenta; si era solo
     * laboral, la deshabilita).
     *
     * @param Request               $request
     * @param Worker                $worker
     * @param WorkerUserProvisioner $provisioner
     * @return Response
     */
    #[Route('/{id}/access/revoke', name: 'staff_access_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revokeAccess(
        Request $request,
        Worker $worker,
        WorkerUserProvisioner $provisioner,
    ): Response {
        if (!$this->isCsrfTokenValid('staff_access' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        $provisioner->revokeAccess($worker);
        $this->addFlash('success', sprintf('Acceso retirado a «%s».', $worker->getName()));

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Alta de trabajador.
     *
     * @param Request                $request
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/new', name: 'staff_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $worker = new Worker();
        $form = $this->createForm(WorkerType::class, $worker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($worker);
            $em->flush();
            $this->addFlash('success', sprintf('Trabajador «%s» creado.', $worker->getName()));

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        return $this->render('staff/new.html.twig', [
            'worker' => $worker,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Edición de trabajador.
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/edit', name: 'staff_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Worker $worker, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(WorkerType::class, $worker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Trabajador «%s» actualizado.', $worker->getName()));

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        return $this->render('staff/edit.html.twig', [
            'worker' => $worker,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Añade un fichaje olvidado al trabajador (entrada o salida con una hora
     * pasada). Lo hace el supervisor desde la ficha.
     *
     * @param Request             $request
     * @param Worker              $worker
     * @param TimeEntryCorrector  $corrector
     * @return Response
     */
    #[Route('/{id}/entry/add', name: 'staff_entry_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addEntry(Request $request, Worker $worker, TimeEntryCorrector $corrector, TimeInputParser $timeInput): Response
    {
        if (!$this->isCsrfTokenValid('staff_entry' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        $type = (string) $request->request->get('type');
        if (!in_array($type, TimeEntry::TYPES, true)) {
            $this->addFlash('error', 'Tipo de fichaje no válido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        $occurredAt = $timeInput->composeDateTime((string) $request->request->get('date'), (string) $request->request->get('time'));
        if ($occurredAt === null) {
            $this->addFlash('error', 'Indica una fecha y hora válidas.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        try {
            $corrector->addEntry($worker, $type, $occurredAt, $this->getUser(), TimeEntry::SOURCE_SUPERVISOR, $this->reasonOrDefault($request));
            $this->addFlash('success', 'Fichaje añadido.');
        } catch (PunchSequenceException|ClosedWorkMonthException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectAfterEntry($request, $worker);
    }

    /**
     * Corrige la hora de un fichaje (anula el original y crea uno nuevo).
     *
     * @param Request             $request
     * @param Worker              $worker
     * @param TimeEntry           $entry      Fichaje a corregir (de este worker).
     * @param TimeEntryCorrector  $corrector
     * @return Response
     */
    #[Route('/{id}/entry/{entryId}/correct', name: 'staff_entry_correct', methods: ['POST'], requirements: ['id' => '\d+', 'entryId' => '\d+'])]
    public function correctEntry(
        Request $request,
        Worker $worker,
        #[MapEntity(id: 'entryId')] TimeEntry $entry,
        TimeEntryCorrector $corrector,
        TimeInputParser $timeInput,
    ): Response {
        if (($error = $this->guardEntry($request, $worker, $entry)) !== null) {
            return $error;
        }

        $occurredAt = $timeInput->timeOnto($entry->getOccurredAt(), (string) $request->request->get('time'));
        if ($occurredAt === null) {
            $this->addFlash('error', 'Indica una hora válida.');

            return $this->redirectAfterEntry($request, $worker);
        }

        try {
            $corrector->correctTime($entry, $occurredAt, $this->getUser(), TimeEntry::SOURCE_SUPERVISOR, $this->reasonOrDefault($request));
            $this->addFlash('success', 'Hora corregida.');
        } catch (PunchSequenceException|ClosedWorkMonthException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectAfterEntry($request, $worker);
    }

    /**
     * Anula un fichaje (fichado por error). No se borra: queda con su traza.
     *
     * @param Request             $request
     * @param Worker              $worker
     * @param TimeEntry           $entry
     * @param TimeEntryCorrector  $corrector
     * @return Response
     */
    #[Route('/{id}/entry/{entryId}/void', name: 'staff_entry_void', methods: ['POST'], requirements: ['id' => '\d+', 'entryId' => '\d+'])]
    public function voidEntry(
        Request $request,
        Worker $worker,
        #[MapEntity(id: 'entryId')] TimeEntry $entry,
        TimeEntryCorrector $corrector,
    ): Response {
        if (($error = $this->guardEntry($request, $worker, $entry)) !== null) {
            return $error;
        }

        try {
            $corrector->voidEntry($entry, $this->getUser(), $this->reasonOrDefault($request));
            $this->addFlash('success', 'Fichaje anulado.');
        } catch (ClosedWorkMonthException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectAfterEntry($request, $worker);
    }

    /**
     * Calendario mensual de fichajes de un trabajador: una celda por día con el
     * total de horas, marca de hueco (laborable sin fichar) o de ausencia. Cada
     * día enlaza a su detalle para editar/añadir.
     *
     * @param Request             $request
     * @param Worker              $worker
     * @param TimeEntryRepository $timeEntries
     * @param AbsenceRepository   $absences
     * @param WorkdayBuilder      $workdays
     * @param MonthGridBuilder    $gridBuilder
     * @param HolidayRepository   $holidays
     * @param WorkScheduleRepository $schedules
     * @return Response
     */
    #[Route('/{id}/calendar', name: 'staff_calendar', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function calendar(
        Request $request,
        Worker $worker,
        TimeEntryRepository $timeEntries,
        AbsenceRepository $absences,
        WorkdayBuilder $workdays,
        MonthGridBuilder $gridBuilder,
        HolidayRepository $holidays,
        WorkScheduleRepository $schedules,
    ): Response {
        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);

        $year = (int) $request->query->get('year', $today->format('Y'));
        $month = (int) $request->query->get('month', $today->format('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) $today->format('n');
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $madrid);
        $monthEnd = $monthStart->modify('first day of next month');

        // Totales por día (Y-m-d Madrid) a partir de los fichajes del mes.
        $entries = $timeEntries->findEffectiveForWorkerBetween($worker, $monthStart, $monthEnd);
        $totals = [];
        foreach ($workdays->buildDays($entries, $madrid) as $day) {
            $totals[$day['date']->format('Y-m-d')] = $day;
        }

        // Días cubiertos por ausencia aprobada → etiqueta corta para la celda.
        $covered = $absences->coveredDaysForWorkerBetween($worker, $monthStart, $monthEnd);

        $holidayDates = $holidays->findNamesBetween($monthStart, $monthEnd->modify('-1 day'));
        $weeks = $gridBuilder->build($year, $month, $madrid, $totals, $covered, $today, $worker->getHireDate(), $holidayDates, $schedules->workingWeekFor($worker));

        return $this->render('staff/calendar.html.twig', [
            'worker' => $worker,
            'weeks' => $weeks,
            'year' => $year,
            'month' => $month,
            'month_label' => $monthStart,
            'prev' => $monthStart->modify('-1 month'),
            'next' => $monthStart->modify('+1 month'),
        ]);
    }

    /**
     * Descarga el PDF del resumen mensual de jornada del trabajador, con el
     * sello de aprobación del mes si ya está aprobado. Lectura: basta
     * ROLE_GESTION_LABORAL.
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param MonthlyTimesheetReport $report
     * @param TimesheetPdfRenderer   $pdf
     * @return Response
     */
    #[Route('/{id}/timesheet.pdf', name: 'staff_timesheet_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function timesheetPdf(
        Request $request,
        Worker $worker,
        MonthlyTimesheetReport $report,
        TimesheetPdfRenderer $pdf,
    ): Response {
        $madrid = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $madrid);
        $currentYear = (int) $today->format('Y');

        $year = (int) $request->query->get('year', (string) $currentYear);
        if ($year < $currentYear - 10 || $year > $currentYear + 1) {
            $year = $currentYear;
        }
        $month = (int) $request->query->get('month', $today->format('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) $today->format('n');
        }

        $sheet = $report->forMonth($worker, $year, $month, $madrid, $today);

        return $pdf->download($worker, $year, $month, $sheet, new \DateTimeImmutable('now', $madrid));
    }

    /**
     * Detalle de un día: los fichajes de esa jornada con los controles para
     * corregir/anular/añadir. Es a donde lleva cada celda del calendario.
     *
     * @param Worker              $worker
     * @param string              $date   Día en formato Y-m-d.
     * @param TimeEntryRepository $timeEntries
     * @param WorkdayBuilder      $workdays
     * @param ClosedWorkMonths    $closedMonths
     * @param AbsenceRepository   $absences
     * @return Response
     */
    #[Route('/{id}/day/{date}', name: 'staff_day', methods: ['GET'], requirements: ['id' => '\d+', 'date' => '\d{4}-\d{2}-\d{2}'])]
    public function day(
        Worker $worker,
        string $date,
        TimeEntryRepository $timeEntries,
        WorkdayBuilder $workdays,
        ClosedWorkMonths $closedMonths,
        AbsenceRepository $absences,
    ): Response {
        $madrid = new \DateTimeZone('Europe/Madrid');

        $dayStart = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $madrid);
        if ($dayStart === false) {
            throw $this->createNotFoundException('Fecha no válida.');
        }
        $dayEnd = $dayStart->modify('+1 day');

        $entries = $timeEntries->findEffectiveForWorkerBetween($worker, $dayStart, $dayEnd);
        $days = $workdays->buildDays($entries, $madrid);

        return $this->render('staff/day.html.twig', [
            'worker' => $worker,
            'date' => $dayStart,
            'segments' => $days[0]['segments'] ?? [],
            'total' => $days[0]['totalMinutes'] ?? 0,
            'open' => $days[0]['open'] ?? false,
            'anomaly' => $days[0]['anomaly'] ?? false,
            'entries' => $entries,
            'voided' => $timeEntries->findVoidedForWorkerBetween($worker, $dayStart, $dayEnd),
            'closed' => $closedMonths->isClosed($worker, $dayStart),
            'absence_type' => $absences->coveredDaysForWorkerBetween($worker, $dayStart, $dayStart)[$date] ?? null,
        ]);
    }

    /**
     * Redirección tras una acción sobre un fichaje: vuelve al día si la acción
     * venía de la vista de día (campo return_to con un Y-m-d), o a la ficha.
     *
     * @param Request $request
     * @param Worker  $worker
     * @return RedirectResponse
     */
    private function redirectAfterEntry(Request $request, Worker $worker): RedirectResponse
    {
        $returnTo = (string) $request->request->get('return_to', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnTo) === 1) {
            return $this->redirectToRoute('staff_day', ['id' => $worker->getId(), 'date' => $returnTo]);
        }

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Valida CSRF y que el fichaje pertenezca al trabajador y siga vigente.
     * Devuelve una redirección de error, o null si todo está bien.
     *
     * @param Request   $request
     * @param Worker    $worker
     * @param TimeEntry $entry
     * @return RedirectResponse|null
     */
    private function guardEntry(Request $request, Worker $worker, TimeEntry $entry): ?RedirectResponse
    {
        if (!$this->isCsrfTokenValid('staff_entry' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        if ($entry->getWorker()?->getId() !== $worker->getId()) {
            throw $this->createNotFoundException('El fichaje no pertenece a este trabajador.');
        }

        if ($entry->isVoided()) {
            $this->addFlash('warning', 'Ese fichaje ya estaba anulado.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        return null;
    }


    /**
     * Motivo de la corrección desde el request, con un texto por defecto si viene
     * vacío (la traza siempre lleva algo).
     *
     * @param Request $request
     * @return string
     */
    private function reasonOrDefault(Request $request): string
    {
        $reason = trim((string) $request->request->get('reason', ''));

        return $reason !== '' ? $reason : 'Corrección manual del supervisor';
    }

    /**
     * Borra un trabajador y sus datos asociados.
     *
     * Por defecto se BLOQUEA si tiene fichajes: el registro de jornada se conserva
     * 4 años por ley aunque la persona se vaya, así que la vía correcta para un
     * trabajador con histórico es marcarlo inactivo, no borrarlo. Solo con
     * `force=1` (acción reservada a ROLE_ADMIN, pensada para limpiar trabajadores
     * de PRUEBA) se borra aun teniendo fichajes,
     * destruyendo el registro de jornada.
     *
     * En cualquier caso el borrado arrastra en cascada lo que solo tiene sentido
     * junto al trabajador (no hay retención legal de esto): sus ausencias, y la
     * cuenta de login si quedaba huérfana ({@see WorkerUserProvisioner::releaseUserOnWorkerDeletion()}).
     * Antes el delete solo miraba `time_entries` y un trabajador con ausencias o
     * con cuenta podía petar por FK o dejar un login muerto.
     *
     * @param Request                 $request
     * @param Worker                  $worker
     * @param EntityManagerInterface  $em
     * @param WorkerUserProvisioner   $provisioner
     * @return Response
     */
    #[Route('/{id}', name: 'staff_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        Request $request,
        Worker $worker,
        EntityManagerInterface $em,
        WorkerUserProvisioner $provisioner,
    ): Response {
        $entryCount = $worker->getTimeEntries()->count();
        $force = $request->request->getBoolean('force');

        // Destruir un registro de jornada que la ley obliga a conservar no es una
        // acción de gestión laboral corriente: sólo admin, aunque el botón no se
        // pinte a nadie más (el POST se puede fabricar igual). Va antes que el
        // CSRF porque no depende de él: sin el permiso, la respuesta es la misma.
        if ($force && $entryCount > 0 && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Sólo administración puede destruir un registro de jornada.');
        }

        if (!$this->isCsrfTokenValid('delete' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        if ($entryCount > 0 && !$force) {
            $this->addFlash('error', sprintf(
                'No se puede borrar «%s»: tiene fichajes registrados (el registro de jornada se conserva por ley). Márcalo como inactivo.',
                $worker->getName(),
            ));

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        $name = $worker->getName();

        // Forzado: se destruye también el registro de jornada (solo limpieza de
        // pruebas; el bloqueo legal lo cubre el caso normal de arriba).
        if ($entryCount > 0) {
            foreach ($worker->getTimeEntries() as $entry) {
                $em->remove($entry);
            }
        }

        // Ausencias: no tienen retención legal, se borran siempre con el trabajador.
        foreach ($worker->getAbsences() as $absence) {
            $em->remove($absence);
        }

        // Cuenta de login: se borra si era solo laboral, se desvincula si es compartida.
        $provisioner->releaseUserOnWorkerDeletion($worker);

        $em->remove($worker);
        $em->flush();

        $this->addFlash('success', $force && $entryCount > 0
            ? sprintf('Trabajador «%s» borrado junto con sus %d fichajes y sus ausencias.', $name, $entryCount)
            : sprintf('Trabajador «%s» borrado.', $name));

        return $this->redirectToRoute('staff_index');
    }
}
