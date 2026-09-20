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

        $times = $this->parseTimes((string) $request->request->get('times', ''));
        if ($times === null) {
            $this->addFlash('error', 'Revisa los tramos: formato "09:00-14:00, 15:30-18:00".');

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
     * Parsea "09:00-14:00, 15:30-18:00" en `[["09:00","14:00"], ["15:30","18:00"]]`.
     * Una cadena vacía es válida (sin tramos, día no laborable). Cualquier tramo
     * mal formado, con horas fuera de rango o con fin antes que inicio invalida
     * TODO el campo (se le pide al admin que lo revise entero, no se guarda a
     * medias).
     *
     * @param string $raw
     * @return list<array{0: string, 1: string}>|null
     */
    private function parseTimes(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $times = [];
        foreach (explode(',', $raw) as $chunk) {
            $chunk = trim($chunk);
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $chunk, $m)) {
                return null;
            }
            $start = $m[1] . ':' . $m[2];
            $end = $m[3] . ':' . $m[4];
            if ($end <= $start) {
                return null;
            }
            $times[] = [$start, $end];
        }

        return $times;
    }
}
