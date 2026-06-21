<?php

namespace App\Controller;

use App\Entity\Absence;
use App\Entity\TimeEntry;
use App\Entity\Worker;
use App\Form\AbsenceType;
use App\Form\WorkerType;
use App\Repository\AbsenceRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkerRepository;
use App\Security\MagicLinkMailer;
use App\Security\WorkerUserProvisioner;
use App\Service\Staff\GapFinder;
use App\Service\Staff\MonthGridBuilder;
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
     * @param WorkerRepository    $workers
     * @param TimeEntryRepository $timeEntries
     * @param AbsenceRepository   $absences
     * @param GapFinder           $gapFinder
     * @return Response
     */
    #[Route('/gaps', name: 'staff_gaps', methods: ['GET'])]
    public function gaps(
        WorkerRepository $workers,
        TimeEntryRepository $timeEntries,
        AbsenceRepository $absences,
        GapFinder $gapFinder,
    ): Response {
        $madrid = new \DateTimeZone('Europe/Madrid');

        $today = new \DateTimeImmutable('today', $madrid);
        $fromMadrid = $today->modify(sprintf('-%d days', self::GAP_DAYS));

        $rows = [];
        foreach ($workers->findActive() as $worker) {
            // Días con algún fichaje (clave Y-m-d en Madrid).
            $worked = [];
            foreach ($timeEntries->findEffectiveForWorkerBetween($worker, $fromMadrid, $today) as $entry) {
                $worked[$entry->getOccurredAt()->format('Y-m-d')] = true;
            }

            // Días cubiertos por una ausencia aprobada (vacaciones, baja, permiso).
            $covered = [];
            foreach ($absences->findApprovedForWorkerBetween($worker, $fromMadrid, $today) as $absence) {
                $cursor = $absence->getStartDate();
                while ($cursor <= $absence->getEndDate()) {
                    $covered[$cursor->format('Y-m-d')] = true;
                    $cursor = $cursor->modify('+1 day');
                }
            }

            $gaps = $gapFinder->gapsFor($fromMadrid, $today, $worker->getHireDate(), $worked, $covered);
            if ($gaps !== []) {
                $rows[] = ['worker' => $worker, 'gaps' => $gaps];
            }
        }

        return $this->render('staff/gaps.html.twig', [
            'rows' => $rows,
            'days' => self::GAP_DAYS,
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
    ): Response {
        $madrid = new \DateTimeZone('Europe/Madrid');

        // Ventana [hoy-N, mañana) en días de Madrid (huso único, sin conversión).
        $startMadrid = (new \DateTimeImmutable('today', $madrid))->modify(sprintf('-%d days', self::RECENT_DAYS - 1));
        $to = new \DateTimeImmutable('tomorrow', $madrid);

        $entries = $timeEntries->findEffectiveForWorkerBetween($worker, $startMadrid, $to);

        // Reconstruye las jornadas (tramos + total) a partir de los fichajes.
        $days = $workdays->buildDays($entries, $madrid);

        $year = (int) (new \DateTimeImmutable('today', $madrid))->format('Y');
        $usedVacationDays = array_sum(array_map(
            static fn ($a) => $a->getCalendarDayCount(),
            $absences->findApprovedVacationsForWorkerInYear($worker, $year),
        ));

        return $this->render('staff/show.html.twig', [
            'worker' => $worker,
            'days' => $days,
            'year' => $year,
            'vacation_used' => $usedVacationDays,
            'vacation_total' => $worker->getAnnualVacationDays(),
            'access_user' => $provisioner->userFor($worker),
            'absences' => $this->sortedAbsences($worker),
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
     * @param Request                $request
     * @param Worker                 $worker
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/absence/new', name: 'staff_absence_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function newAbsence(Request $request, Worker $worker, EntityManagerInterface $em): Response
    {
        $absence = (new Absence())->setWorker($worker);
        $form = $this->createForm(AbsenceType::class, $absence);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $absence->setStatus(Absence::STATUS_APPROVED);
            $absence->setApprovedBy($this->getUser());
            $em->persist($absence);
            $em->flush();
            $this->addFlash('success', 'Ausencia registrada.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        return $this->render('staff/absence_new.html.twig', [
            'worker' => $worker,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Aprueba una solicitud de ausencia (la que pidió el trabajador).
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param Absence                $absence
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/absence/{absenceId}/approve', name: 'staff_absence_approve', methods: ['POST'], requirements: ['id' => '\d+', 'absenceId' => '\d+'])]
    public function approveAbsence(
        Request $request,
        Worker $worker,
        #[MapEntity(id: 'absenceId')] Absence $absence,
        EntityManagerInterface $em,
    ): Response {
        return $this->changeAbsenceStatus($request, $worker, $absence, Absence::STATUS_APPROVED, 'Ausencia aprobada.', $em);
    }

    /**
     * Rechaza una solicitud de ausencia.
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param Absence                $absence
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/absence/{absenceId}/reject', name: 'staff_absence_reject', methods: ['POST'], requirements: ['id' => '\d+', 'absenceId' => '\d+'])]
    public function rejectAbsence(
        Request $request,
        Worker $worker,
        #[MapEntity(id: 'absenceId')] Absence $absence,
        EntityManagerInterface $em,
    ): Response {
        return $this->changeAbsenceStatus($request, $worker, $absence, Absence::STATUS_REJECTED, 'Solicitud rechazada.', $em);
    }

    /**
     * Cancela una ausencia (aprobada o solicitada).
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
        return $this->changeAbsenceStatus($request, $worker, $absence, Absence::STATUS_CANCELLED, 'Ausencia cancelada.', $em);
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
     * @return Response
     */
    private function changeAbsenceStatus(
        Request $request,
        Worker $worker,
        Absence $absence,
        string $status,
        string $message,
        EntityManagerInterface $em,
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
        $this->addFlash('success', $message);

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Da acceso de login al trabajador por email (reusa cuenta existente o crea
     * una) y le envía el enlace de acceso. La escritura la exige
     * ROLE_GESTION_LABORAL_EDIT en access_control.
     *
     * @param Request                 $request
     * @param Worker                  $worker
     * @param WorkerUserProvisioner   $provisioner
     * @param MagicLinkMailer         $magicLinkMailer
     * @return Response
     */
    #[Route('/{id}/access/grant', name: 'staff_access_grant', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function grantAccess(
        Request $request,
        Worker $worker,
        WorkerUserProvisioner $provisioner,
        MagicLinkMailer $magicLinkMailer,
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

        if ($magicLinkMailer->send($user)) {
            $this->addFlash('success', sprintf('Acceso concedido a %s. Le hemos enviado un enlace para entrar.', $user->getEmail()));
        } else {
            $this->addFlash('warning', sprintf('Acceso concedido a %s, pero no se pudo enviar el enlace. Reenvíalo desde su ficha.', $user->getEmail()));
        }

        return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
    }

    /**
     * Reenvía el enlace de acceso a la cuenta del trabajador.
     *
     * @param Request               $request
     * @param Worker                $worker
     * @param WorkerUserProvisioner $provisioner
     * @param MagicLinkMailer       $magicLinkMailer
     * @return Response
     */
    #[Route('/{id}/access/resend', name: 'staff_access_resend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendAccess(
        Request $request,
        Worker $worker,
        WorkerUserProvisioner $provisioner,
        MagicLinkMailer $magicLinkMailer,
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

        if ($magicLinkMailer->send($user)) {
            $this->addFlash('success', sprintf('Enlace de acceso reenviado a %s.', $user->getEmail()));
        } else {
            $this->addFlash('error', 'No se pudo enviar el enlace. Revisa la configuración de correo.');
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

        $corrector->addEntry($worker, $type, $occurredAt, $this->getUser(), TimeEntry::SOURCE_SUPERVISOR, $this->reasonOrDefault($request));
        $this->addFlash('success', 'Fichaje añadido.');

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

        $corrector->correctTime($entry, $occurredAt, $this->getUser(), TimeEntry::SOURCE_SUPERVISOR, $this->reasonOrDefault($request));
        $this->addFlash('success', 'Hora corregida.');

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

        $corrector->voidEntry($entry, $this->getUser(), $this->reasonOrDefault($request));
        $this->addFlash('success', 'Fichaje anulado.');

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
        $covered = [];
        foreach ($absences->findApprovedForWorkerBetween($worker, $monthStart, $monthEnd) as $absence) {
            $cursor = $absence->getStartDate();
            while ($cursor <= $absence->getEndDate()) {
                $covered[$cursor->format('Y-m-d')] = $absence->getType();
                $cursor = $cursor->modify('+1 day');
            }
        }

        $weeks = $gridBuilder->build($year, $month, $madrid, $totals, $covered, $today, $worker->getHireDate());

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
     * Detalle de un día: los fichajes de esa jornada con los controles para
     * corregir/anular/añadir. Es a donde lleva cada celda del calendario.
     *
     * @param Worker              $worker
     * @param string              $date   Día en formato Y-m-d.
     * @param TimeEntryRepository $timeEntries
     * @param WorkdayBuilder      $workdays
     * @return Response
     */
    #[Route('/{id}/day/{date}', name: 'staff_day', methods: ['GET'], requirements: ['id' => '\d+', 'date' => '\d{4}-\d{2}-\d{2}'])]
    public function day(
        Worker $worker,
        string $date,
        TimeEntryRepository $timeEntries,
        WorkdayBuilder $workdays,
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
     * Borra un trabajador. No se permite si tiene fichajes: el registro de
     * jornada se conserva 4 años por ley aunque la persona se vaya, así que para
     * un trabajador con histórico la vía correcta es marcarlo inactivo, no
     * borrarlo.
     *
     * @param Request                $request
     * @param Worker                 $worker
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}', name: 'staff_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Worker $worker, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $worker->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        if ($worker->getTimeEntries()->count() > 0) {
            $this->addFlash('error', sprintf(
                'No se puede borrar «%s»: tiene fichajes registrados (el registro de jornada se conserva por ley). Márcalo como inactivo.',
                $worker->getName(),
            ));

            return $this->redirectToRoute('staff_show', ['id' => $worker->getId()]);
        }

        $em->remove($worker);
        $em->flush();
        $this->addFlash('success', sprintf('Trabajador «%s» borrado.', $worker->getName()));

        return $this->redirectToRoute('staff_index');
    }
}
