<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Genera el PDF del listado de reparto, semanal y mensual.
 *
 * Existe para que el listado que se IMPRIME desde /gestion/reparto y el que se
 * ENVÍA por correo al cerrar el reparto sean el mismo documento. Mientras la
 * generación vivía dentro de {@see \App\Controller\DeliveryController} sólo podía
 * usarla quien tuviera una petición HTTP delante, así que la tarea programada
 * habría tenido que recrearla — y el día que cambiase el listado, el correo
 * habría empezado a mandar otra cosa que la impresión.
 *
 * Devuelve BYTES, no una Response: quien la pide por HTTP les pone las cabeceras
 * de descarga, y quien la manda por correo los adjunta. La decisión "piedra o
 * dibujo" de cada nodo ({@see sheetFor}) también vive aquí por el mismo motivo.
 */
class DeliverySheetPdf
{
    public function __construct(
        private readonly Environment $twig,
        private readonly NodeDeliverySheet $sheetBuilder,
        private readonly DeliveryModeResolver $modeResolver,
        private readonly WeeklyBasketGenerator $generator,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Hoja de reparto de un nodo en un viernes-ciclo: de PIEDRA si la semana está
     * materializada ({@see NodeDeliverySheet::build}); DIBUJADA al vuelo desde la
     * proyección si al nodo le toca pero aún no está materializada, sin congelarla;
     * o null (EMPTY) si ese nodo no reparte ese día.
     *
     * La decisión NO es global del Basket sino por nodo: un cierre que desplaza la
     * cadencia de un nodo quincenal lo deja sin piedra en la semana nueva aunque
     * otro nodo sí esté materializado.
     *
     * @param Node   $node   Nodo del que se quiere la hoja.
     * @param Basket $basket Cesta-ciclo (viernes) sobre la que se construye.
     * @return array|null Estructura de {@see NodeDeliverySheet}, o null si EMPTY.
     */
    public function sheetFor(Node $node, Basket $basket): ?array
    {
        return match ($this->modeResolver->mode($node, $basket)) {
            DeliveryModeResolver::STONE => $this->sheetBuilder->build($node, $basket),
            DeliveryModeResolver::DRAW => $this->sheetBuilder->shape(array_merge(
                $this->generator->projectLinesForNode($node, $basket),
                $this->sheetBuilder->helperLines($node, $basket),
            )),
            default => null,
        };
    }

    /**
     * Listado semanal de los nodos indicados, un nodo por hoja.
     *
     * Devuelve null cuando ninguno de los nodos reparte ese día (festivo global,
     * todos quincenales fuera de fase…): un PDF de cero hojas no es un documento,
     * y cada caller decide qué hacer con esa ausencia — la pantalla avisa y vuelve
     * al selector, la tarea programada no manda correo.
     *
     * @param Basket $basket Cesta-ciclo (viernes) del listado.
     * @param Node[] $nodes  Nodos a incluir, en el orden en que se quieren impresos.
     * @return string|null Bytes del PDF, o null si ningún nodo reparte.
     */
    public function renderWeekly(Basket $basket, array $nodes): ?string
    {
        $sheets = [];
        foreach ($nodes as $node) {
            $sheet = $this->sheetFor($node, $basket);
            if ($sheet === null) {
                continue;
            }
            $sheets[] = [
                'node' => $node,
                'physical_date' => $this->nodeDeliveryDate->physicalDateFor($basket, $node),
                'sheet' => $sheet,
            ];
        }

        if ($sheets === []) {
            return null;
        }

        return $this->toPdf(
            $this->twig->render('delivery/printable.html.twig', [
                'basket' => $basket,
                'sheets' => $sheets,
            ]),
            'portrait',
        );
    }

    /**
     * Listado MENSUAL en formato matriz (apaisado): una fila por socix y una
     * columna por viernes del mes.
     *
     * @param array $matrix Matriz ya construida por {@see MonthlyDeliveryMatrix::build}.
     * @param int   $year   Año del periodo.
     * @param int   $month  Mes del periodo (1-12).
     * @return string Bytes del PDF.
     */
    public function renderMonthly(array $matrix, int $year, int $month): string
    {
        return $this->toPdf(
            $this->twig->render('delivery/printable_monthly.html.twig', [
                'matrix' => $matrix,
                'year' => $year,
                'month' => $month,
            ]),
            'landscape',
        );
    }

    /**
     * Nombre de fichero del listado semanal, estable entre la descarga y el
     * adjunto del correo.
     *
     * @param Basket $basket Cesta-ciclo del listado.
     */
    public function weeklyFilename(Basket $basket): string
    {
        return sprintf('reparto-%s.pdf', $basket->getDate()->format('Y-m-d'));
    }

    /**
     * Nombre de fichero del listado mensual.
     *
     * @param int $year  Año del periodo.
     * @param int $month Mes del periodo (1-12).
     */
    public function monthlyFilename(int $year, int $month): string
    {
        return sprintf('reparto-mensual-%04d-%02d.pdf', $year, $month);
    }

    /**
     * Pasa el HTML por dompdf. DejaVu Sans porque la fuente por defecto rompe los
     * acentos y la ñ, y ambos listados son de nombres de personas.
     *
     * @param string $html        Documento ya renderizado por Twig.
     * @param string $orientation 'portrait' o 'landscape'.
     * @return string Bytes del PDF.
     */
    private function toPdf(string $html, string $orientation): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf->output();
    }
}
