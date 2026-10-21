<?php

namespace App\Controller;

use App\Service\Staff\StaffRecordExport;
use App\Service\Staff\WorkMonth;
use App\Service\Staff\WorkPeriod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * El registro de jornada de todo el equipo en un periodo, para entregarlo a la
 * Inspección de Trabajo cuando lo pide: se elige el periodo y se descarga en PDF,
 * en CSV diario y en CSV de traza ({@see StaffRecordExport}).
 *
 * Cuelga de /gestion/staff: basta ROLE_GESTION_LABORAL, el mismo permiso que ya
 * deja ver el registro de cada trabajador.
 */
#[Route('/gestion/staff/record')]
#[IsGranted('FEATURE_LABORAL')]
#[IsGranted('ROLE_GESTION_LABORAL')]
class StaffRecordController extends AbstractController
{
    /** Cada formato: sufijo del fichero, extensión y tipo de contenido. */
    private const FORMATS = [
        'pdf' => ['', 'pdf', 'application/pdf'],
        'daily' => ['-diario', 'csv', 'text/csv; charset=UTF-8'],
        'trail' => ['-traza', 'csv', 'text/csv; charset=UTF-8'],
    ];

    /**
     * La pantalla para elegir el periodo. Por defecto, de enero al mes en curso.
     * Con un periodo válido enseña sus descargas: los dos CSV del periodo entero
     * y un PDF por cada año natural ({@see StaffRecordExport::pdf()}).
     *
     * @param Request $request
     * @return Response
     */
    #[Route('', name: 'staff_record', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $current = WorkMonth::containing($this->now());
        $from = (string) $request->query->get('from', WorkMonth::of($current->year, 1)->key());
        $to = (string) $request->query->get('to', $current->key());

        $period = null;
        $error = null;
        if ($request->query->has('from')) {
            try {
                $period = $this->period($from, $to);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('staff/record.html.twig', [
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'error' => $error,
            'current' => $current,
            'max_months' => WorkPeriod::MAX_MONTHS,
        ]);
    }

    /**
     * Descarga el registro del periodo en el formato pedido. Un periodo que no
     * vale (al revés, más largo que el tope, mal escrito o, en PDF, de más de un
     * año) vuelve a la pantalla con el motivo.
     *
     * @param Request           $request
     * @param string            $format  'pdf', 'daily' o 'trail'.
     * @param StaffRecordExport $export
     * @return Response
     */
    #[Route('/download/{format}', name: 'staff_record_download', methods: ['GET'], requirements: ['format' => 'pdf|daily|trail'])]
    public function download(Request $request, string $format, StaffRecordExport $export): Response
    {
        $from = (string) $request->query->get('from');
        $to = (string) $request->query->get('to');
        $now = $this->now();
        try {
            $period = $this->period($from, $to);
            $content = match ($format) {
                'pdf' => $export->pdf($period, $now),
                'daily' => $export->dailyCsv($period, $now),
                'trail' => $export->trailCsv($period),
            };
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('staff_record', ['from' => $from, 'to' => $to]);
        }
        [$suffix, $ext, $type] = self::FORMATS[$format];

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $export->filename($period, $suffix, $ext)),
        ]);
    }

    /**
     * El periodo de dos claves 'YYYY-MM'.
     *
     * @param string $from Primer mes.
     * @param string $to   Último mes.
     * @return WorkPeriod
     *
     * @throws \InvalidArgumentException Si alguna clave no vale o el periodo no es válido.
     */
    private function period(string $from, string $to): WorkPeriod
    {
        return WorkPeriod::between(WorkMonth::fromKey($from), WorkMonth::fromKey($to));
    }

    /**
     * @return \DateTimeImmutable Ahora, en Madrid.
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
    }
}
