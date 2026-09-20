<?php

namespace App\Controller;

use App\Entity\Worker;
use App\Entity\WorkSchedule;
use App\Repository\WorkScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Configuración del horario habitual: la plantilla GLOBAL de toda la asociación
 * y, por trabajador, la excepción que la sobrescribe día a día (p. ej. alguien a
 * media jornada). Es lo que consulta {@see \App\Service\Staff\ScheduleAutofill}
 * para el botón "rellenar con mi horario" del panel del trabajador.
 *
 * Cuelga del área de personal (/gestion/staff), mismo patrón de permisos que
 * {@see HolidayController}: lectura con ROLE_GESTION_LABORAL, escritura (POST)
 * con ROLE_GESTION_LABORAL_EDIT vía access_control de ^/gestion/staff.
 */
#[Route('/gestion/staff/schedule')]
#[IsGranted('FEATURE_LABORAL')]
#[IsGranted('ROLE_GESTION_LABORAL')]
class WorkScheduleController extends AbstractController
{
    /** Nombres de los 7 días ISO-8601 (1 lunes .. 7 domingo), para pintar las filas. */
    private const DAY_NAMES = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    /**
     * Plantilla global: las 7 filas de la semana con sus tramos.
     *
     * @param WorkScheduleRepository $schedules
     * @return Response
     */
    #[Route('', name: 'staff_schedule', methods: ['GET'])]
    public function global(WorkScheduleRepository $schedules): Response
    {
        return $this->render('staff/schedule.html.twig', [
            'worker' => null,
            'week' => $schedules->findWeekFor(null),
            'day_names' => self::DAY_NAMES,
        ]);
    }

    /**
     * Excepción de un trabajador: las 7 filas de SU semana. Los días sin fila
     * propia siguen la plantilla global (se pinta atenuado, informativo).
     *
     * @param Worker                 $worker
     * @param WorkScheduleRepository $schedules
     * @return Response
     */
    #[Route('/{workerId}', name: 'staff_schedule_worker', methods: ['GET'], requirements: ['workerId' => '\d+'])]
    public function forWorker(
        #[MapEntity(id: 'workerId')] Worker $worker,
        WorkScheduleRepository $schedules,
    ): Response {
        return $this->render('staff/schedule.html.twig', [
            'worker' => $worker,
            'week' => $schedules->findWeekFor($worker),
            'global_week' => $schedules->findWeekFor(null),
            'day_names' => self::DAY_NAMES,
        ]);
    }

    /**
     * Guarda los tramos de un día (global o de un trabajador): find-or-create
     * sobre esa fila exacta, nunca un insert libre — así no puede haber dos
     * filas del mismo (worker, día).
     *
     * @param Request                $request
     * @param int                    $dayOfWeek
     * @param WorkScheduleRepository $schedules
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{dayOfWeek}/save', name: 'staff_schedule_save', methods: ['POST'], requirements: ['dayOfWeek' => '[1-7]'])]
    public function save(
        Request $request,
        int $dayOfWeek,
        WorkScheduleRepository $schedules,
        EntityManagerInterface $em,
    ): Response {
        return $this->saveDay($request, $dayOfWeek, null, $schedules, $em);
    }

    /**
     * @see self::save() Misma acción, pero sobre la excepción de un trabajador.
     */
    #[Route('/{workerId}/{dayOfWeek}/save', name: 'staff_schedule_worker_save', methods: ['POST'], requirements: ['workerId' => '\d+', 'dayOfWeek' => '[1-7]'])]
    public function saveForWorker(
        Request $request,
        int $dayOfWeek,
        #[MapEntity(id: 'workerId')] Worker $worker,
        WorkScheduleRepository $schedules,
        EntityManagerInterface $em,
    ): Response {
        return $this->saveDay($request, $dayOfWeek, $worker, $schedules, $em);
    }

    /**
     * Borra los tramos de un día (deja de trabajarse ese día): elimina la fila.
     *
     * @param Request                $request
     * @param int                    $dayOfWeek
     * @param WorkScheduleRepository $schedules
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{dayOfWeek}/clear', name: 'staff_schedule_clear', methods: ['POST'], requirements: ['dayOfWeek' => '[1-7]'])]
    public function clear(Request $request, int $dayOfWeek, WorkScheduleRepository $schedules, EntityManagerInterface $em): Response
    {
        return $this->clearDay($request, $dayOfWeek, null, $schedules, $em);
    }

    /**
     * @see self::clear() Misma acción, pero sobre la excepción de un trabajador.
     */
    #[Route('/{workerId}/{dayOfWeek}/clear', name: 'staff_schedule_worker_clear', methods: ['POST'], requirements: ['workerId' => '\d+', 'dayOfWeek' => '[1-7]'])]
    public function clearForWorker(
        Request $request,
        int $dayOfWeek,
        #[MapEntity(id: 'workerId')] Worker $worker,
        WorkScheduleRepository $schedules,
        EntityManagerInterface $em,
    ): Response {
        return $this->clearDay($request, $dayOfWeek, $worker, $schedules, $em);
    }

    /**
     * @param Request                $request
     * @param int                    $dayOfWeek
     * @param Worker|null            $worker
     * @param WorkScheduleRepository $schedules
     * @param EntityManagerInterface $em
     * @return Response
     */
    private function saveDay(
        Request $request,
        int $dayOfWeek,
        ?Worker $worker,
        WorkScheduleRepository $schedules,
        EntityManagerInterface $em,
    ): Response {
        if (($error = $this->guardCsrf($request, $worker)) !== null) {
            return $error;
        }

        $times = $this->parseTramos($request);
        if ($times === null) {
            $this->addFlash('error', 'Revisa los tramos: cada uno necesita hora de inicio y fin, el fin después del inicio, y el tramo 2 después del tramo 1.');

            return $this->redirectToSchedule($worker);
        }

        $schedule = $schedules->findOneForSlot($worker, $dayOfWeek) ?? (new WorkSchedule())->setWorker($worker)->setDayOfWeek($dayOfWeek);
        $schedule->setTimes($times);
        $em->persist($schedule);
        $em->flush();

        $this->addFlash('success', 'Horario guardado.');

        return $this->redirectToSchedule($worker);
    }

    /**
     * @param Request                $request
     * @param int                    $dayOfWeek
     * @param Worker|null            $worker
     * @param WorkScheduleRepository $schedules
     * @param EntityManagerInterface $em
     * @return Response
     */
    private function clearDay(
        Request $request,
        int $dayOfWeek,
        ?Worker $worker,
        WorkScheduleRepository $schedules,
        EntityManagerInterface $em,
    ): Response {
        if (($error = $this->guardCsrf($request, $worker)) !== null) {
            return $error;
        }

        $schedule = $schedules->findOneForSlot($worker, $dayOfWeek);
        if ($schedule !== null) {
            $em->remove($schedule);
            $em->flush();
        }

        $this->addFlash('success', $worker !== null
            ? 'Excepción borrada: ese día vuelve a seguir el horario global.'
            : 'Ese día se marca como no laborable en la plantilla global.');

        return $this->redirectToSchedule($worker);
    }

    /**
     * @param Request     $request
     * @param Worker|null $worker
     * @return Response|null
     */
    private function guardCsrf(Request $request, ?Worker $worker): ?Response
    {
        $tokenId = 'staff_schedule' . ($worker?->getId() ?? '');
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToSchedule($worker);
        }

        return null;
    }

    /**
     * @param Worker|null $worker
     * @return Response
     */
    private function redirectToSchedule(?Worker $worker): Response
    {
        return $worker !== null
            ? $this->redirectToRoute('staff_schedule_worker', ['workerId' => $worker->getId()])
            : $this->redirectToRoute('staff_schedule');
    }

    /**
     * Lee hasta 2 tramos de los `<input type="time">` del formulario (t1s/t1e,
     * t2s/t2e) y los valida: cada tramo presente necesita inicio Y fin (no vale
     * uno solo), el fin va después del inicio, el tramo 2 no puede empezar antes
     * de que acabe el tramo 1 (evita solapes), y el tramo 2 no tiene sentido sin
     * el tramo 1. Los dos vacíos son válidos: día no laborable.
     *
     * @param Request $request
     * @return list<array{0: string, 1: string}>|null Tramos, o null si algo no cuadra.
     */
    private function parseTramos(Request $request): ?array
    {
        $tramo1 = $this->readTramo($request, 't1s', 't1e');
        $tramo2 = $this->readTramo($request, 't2s', 't2e');
        if ($tramo1 === false || $tramo2 === false) {
            return null;
        }

        if ($tramo1 === null) {
            // Sin tramo 1 no puede haber tramo 2: sería un día "solo tarde" sin
            // mañana, que no es un caso real de la asociación (YAGNI).
            return $tramo2 === null ? [] : null;
        }

        if ($tramo2 === null) {
            return [$tramo1];
        }

        return $tramo2[0] < $tramo1[1] ? null : [$tramo1, $tramo2];
    }

    /**
     * Lee y valida un único tramo desde dos campos `H:i` del request.
     *
     * @param Request $request
     * @param string  $startField
     * @param string  $endField
     * @return array{0: string, 1: string}|null|false El tramo, null si está vacío, false si es inválido.
     */
    private function readTramo(Request $request, string $startField, string $endField): array|null|false
    {
        $start = trim((string) $request->request->get($startField, ''));
        $end = trim((string) $request->request->get($endField, ''));

        if ($start === '' && $end === '') {
            return null;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
            return false;
        }
        if ($end <= $start) {
            return false;
        }

        return [$start, $end];
    }
}
