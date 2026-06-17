<?php

namespace App\Controller;

use App\Entity\Stay;
use App\Repository\StayRepository;
use App\Service\Hosting\HostingStatsBuilder;
use App\Service\Hosting\HostingTimelineBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vistas transversales del albergue (Fase 2): el calendario de ocupación
 * (timeline tipo Gantt) y el panel "hoy en el albergue". El CRUD vive en
 * {@see HelperController} / {@see StayController} / {@see HostingCapacityController}.
 *
 * Módulo albergue, Fase 2 (2026-06-17).
 */
#[Route('/gestion/albergue')]
#[IsGranted('ROLE_GESTION_ALBERGUE')]
class AlbergueController extends AbstractController
{
    /** Nº de meses que abarca la ventana del timeline. */
    private const TIMELINE_MONTHS = 3;

    /**
     * Calendario de ocupación: una fila por voluntario con sus estancias, la
     * ocupación diaria con su estado, y el panel de quién está hoy.
     *
     * @param Request $request
     * @param HostingTimelineBuilder $timelineBuilder
     * @param StayRepository $stayRepository
     * @return Response
     */
    #[Route('/timeline', name: 'albergue_timeline', methods: ['GET'])]
    public function timeline(
        Request $request,
        HostingTimelineBuilder $timelineBuilder,
        StayRepository $stayRepository,
    ): Response {
        $from = $this->windowStart($request->query->get('from'));
        $to = $from->modify(sprintf('+%d months', self::TIMELINE_MONTHS));

        $today = new \DateTimeImmutable('today');
        $currentStays = $stayRepository->findOverlapping(
            $today,
            $today->modify('+1 day'),
            [Stay::STATUS_CONFIRMED],
        );

        return $this->render('albergue/timeline.html.twig', [
            'timeline' => $timelineBuilder->build($from, $to),
            'today' => $today,
            'current_stays' => $currentStays,
            'prev_month' => $from->modify('-1 month')->format('Y-m'),
            'next_month' => $from->modify('+1 month')->format('Y-m'),
        ]);
    }

    /**
     * Métricas por procedencia: cuántos voluntarios, estancias y noches por
     * origen en el año seleccionado.
     *
     * @param Request $request
     * @param HostingStatsBuilder $statsBuilder
     * @return Response
     */
    #[Route('/stats', name: 'albergue_stats', methods: ['GET'])]
    public function stats(Request $request, HostingStatsBuilder $statsBuilder): Response
    {
        $year = (int) $request->query->get('year', (new \DateTimeImmutable('today'))->format('Y'));

        return $this->render('albergue/stats.html.twig', [
            'stats' => $statsBuilder->bySource($year),
            'prev_year' => $year - 1,
            'next_year' => $year + 1,
        ]);
    }

    /**
     * Primer día de la ventana: el día 1 del mes pedido ("YYYY-MM"), o del mes
     * actual si el parámetro falta o es inválido.
     *
     * @param mixed $monthParam
     * @return \DateTimeImmutable
     */
    private function windowStart(mixed $monthParam): \DateTimeImmutable
    {
        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $monthParam . '-01');
            if ($parsed !== false) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        return new \DateTimeImmutable('first day of this month 00:00');
    }
}
