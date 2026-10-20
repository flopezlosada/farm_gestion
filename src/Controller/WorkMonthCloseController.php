<?php

namespace App\Controller;

use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Service\Staff\WorkMonth;
use App\Service\Staff\WorkMonthClosing;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cierre mensual del registro de jornada: la pantalla donde gestión laboral
 * revisa el mes de cada trabajador y lo aprueba (o lo reabre para corregirlo).
 *
 * Cuelga de /gestion/staff, así que hereda sus permisos: verla pide
 * ROLE_GESTION_LABORAL y aprobar o reabrir (POST) ROLE_GESTION_LABORAL_EDIT, vía
 * access_control.
 */
#[Route('/gestion/staff/close')]
#[IsGranted('FEATURE_LABORAL')]
#[IsGranted('ROLE_GESTION_LABORAL')]
class WorkMonthCloseController extends AbstractController
{
    /**
     * El mes de cada trabajador con su estado. Por defecto, el mes anterior: es el
     * que toca aprobar.
     *
     * @param Request          $request
     * @param WorkMonthClosing $closing
     * @return Response
     */
    #[Route('', name: 'staff_month_close', methods: ['GET'])]
    public function index(Request $request, WorkMonthClosing $closing): Response
    {
        $now = $this->now();
        $current = WorkMonth::containing($now);
        $month = $this->monthFrom($request->query->get('year'), $request->query->get('month')) ?? $current->previous();
        // Más allá del mes en curso no hay nada que revisar.
        if ($month->start() > $current->start()) {
            $month = $current;
        }

        $rows = $closing->rowsFor($month, $now->setTime(0, 0));

        return $this->render('staff/month_close.html.twig', [
            'month' => $month,
            'rows' => $rows,
            'ended' => $month->hasEndedAt($now),
            'prev' => $month->previous(),
            'next' => $month->equals($current) ? null : $month->next(),
            'stats' => [
                'approved' => \count(array_filter($rows, static fn (array $r) => $r['close'] !== null)),
                'pending' => \count(array_filter($rows, static fn (array $r) => $r['close'] === null)),
                'issues' => \count(array_filter($rows, static fn (array $r) => $r['close'] === null && $r['issues'] > 0)),
            ],
        ]);
    }

    /**
     * Aprueba el mes de un trabajador.
     *
     * @param Request          $request
     * @param Worker           $worker
     * @param WorkMonthClosing $closing
     * @return Response
     */
    #[Route('/approve/{workerId}', name: 'staff_month_close_approve', methods: ['POST'], requirements: ['workerId' => '\d+'])]
    public function approve(
        Request $request,
        #[MapEntity(id: 'workerId')] Worker $worker,
        WorkMonthClosing $closing,
    ): Response {
        $month = $this->monthFrom($request->request->get('year'), $request->request->get('month'));
        if ($month === null) {
            throw $this->createNotFoundException('Mes no válido.');
        }

        if (!$this->isCsrfTokenValid('staff_month_close', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->backTo($month);
        }

        try {
            $closing->approve($worker, $month, $this->getUser(), $this->now());
            $this->addFlash('success', sprintf('Aprobado %s de %s.', $month->label(), $worker->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($month);
    }

    /**
     * Reabre un mes aprobado para poder corregirlo.
     *
     * @param Request          $request
     * @param WorkMonthClose   $close
     * @param WorkMonthClosing $closing
     * @return Response
     */
    #[Route('/{closeId}/reopen', name: 'staff_month_close_reopen', methods: ['POST'], requirements: ['closeId' => '\d+'])]
    public function reopen(
        Request $request,
        #[MapEntity(id: 'closeId')] WorkMonthClose $close,
        WorkMonthClosing $closing,
    ): Response {
        $month = $close->getMonth();

        if (!$this->isCsrfTokenValid('staff_month_close', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->backTo($month);
        }

        try {
            $closing->reopen($close, $this->getUser(), $this->now());
            $this->addFlash('success', sprintf('Reabierto %s de %s. Cuando esté corregido, vuelve a aprobarlo.', $month->label(), $close->getWorker()->getName()));
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->backTo($month);
    }

    /**
     * Mes a partir de los parámetros de la petición, o null si faltan o no valen.
     *
     * @param mixed $year
     * @param mixed $month
     * @return WorkMonth|null
     */
    private function monthFrom(mixed $year, mixed $month): ?WorkMonth
    {
        if (!is_numeric($year) || !is_numeric($month)) {
            return null;
        }

        try {
            return WorkMonth::of((int) $year, (int) $month);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Vuelve a la pantalla de cierre del mes.
     *
     * @param WorkMonth $month
     * @return RedirectResponse
     */
    private function backTo(WorkMonth $month): RedirectResponse
    {
        return $this->redirectToRoute('staff_month_close', ['year' => $month->year, 'month' => $month->month]);
    }

    /**
     * Ahora, en hora de Madrid.
     *
     * @return \DateTimeImmutable
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
    }
}
