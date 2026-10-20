<?php

namespace App\Service\Staff;

use App\Entity\Worker;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\SluggerInterface;
use Twig\Environment;

/**
 * Renderiza el PDF del resumen mensual de jornada a partir de la hoja ya
 * construida ({@see MonthlyTimesheetReport}). Aísla el uso de dompdf para que lo
 * compartan el panel del supervisor, el del trabajador y el envío por correo sin
 * duplicar la configuración de la fuente ni las cabeceras de descarga.
 */
class TimesheetPdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * Genera la respuesta de descarga del PDF.
     *
     * @param Worker             $worker      Trabajador del resumen.
     * @param int                $year        Año del periodo.
     * @param int                $month       Mes del periodo (1-12).
     * @param array              $sheet       Hoja de {@see MonthlyTimesheetReport::forMonth()}.
     * @param \DateTimeImmutable $generatedAt Instante de generación (para el sello del documento).
     * @return Response Respuesta PDF con cabecera de descarga.
     */
    public function download(Worker $worker, int $year, int $month, array $sheet, \DateTimeImmutable $generatedAt): Response
    {
        return new Response($this->render($worker, $year, $month, $sheet, $generatedAt), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->filename($worker, $year, $month)),
        ]);
    }

    /**
     * El PDF en bruto, para adjuntarlo a un correo sin pasar por una respuesta HTTP.
     *
     * @param Worker             $worker      Trabajador del resumen.
     * @param int                $year        Año del periodo.
     * @param int                $month       Mes del periodo (1-12).
     * @param array              $sheet       Hoja de {@see MonthlyTimesheetReport::forMonth()}.
     * @param \DateTimeImmutable $generatedAt Instante de generación.
     * @return string Bytes del PDF.
     */
    public function render(Worker $worker, int $year, int $month, array $sheet, \DateTimeImmutable $generatedAt): string
    {
        $html = $this->twig->render('staff/timesheet_pdf.html.twig', [
            'worker' => $worker,
            'sheet' => $sheet,
            'year' => $year,
            'month' => $month,
            'generated_at' => $generatedAt,
        ]);

        // DejaVu Sans: dompdf necesita una fuente con cobertura latina completa
        // para acentos y la ñ (la fuente por defecto los rompe).
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Nombre de fichero del resumen ("jornada-nombre-2026-03.pdf").
     *
     * @param Worker $worker Trabajador.
     * @param int    $year   Año.
     * @param int    $month  Mes (1-12).
     * @return string
     */
    public function filename(Worker $worker, int $year, int $month): string
    {
        $slug = $this->slugger->slug((string) $worker->getName())->lower();

        return sprintf('jornada-%s-%04d-%02d.pdf', $slug, $year, $month);
    }
}
