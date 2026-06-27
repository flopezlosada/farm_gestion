<?php

namespace App\Controller;

use App\Entity\UsageHit;
use App\Repository\UsageHitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Estadísticas de uso de la zona privada a partir del rastro anónimo de
 * {@see UsageHit}. Repartidas en tres sub-pantallas (resumen temporal,
 * pantallas y errores) que comparten cabecera, filtros (área + rango de fechas)
 * y KPIs. Solo administración.
 */
#[Route('/gestion/usage-stats')]
#[IsGranted('ROLE_ADMIN')]
class UsageStatsController extends AbstractController
{
    /** Áreas seleccionables y su etiqueta para la UI. */
    private const AREAS = [
        UsageHit::AREA_PANEL => 'Socixs',
        UsageHit::AREA_GESTION => 'Gestión',
    ];

    /** Atajos de rango (días => etiqueta) que fijan desde/hasta de un clic. */
    private const PRESETS = [
        7 => '7 días',
        30 => '30 días',
        90 => '90 días',
        365 => '1 año',
    ];

    /** Tope de rango para no escanear sin límite. */
    private const MAX_DAYS = 366;

    #[Route('', name: 'usage_stats_index', methods: ['GET'])]
    public function index(Request $request, UsageHitRepository $hits): Response
    {
        $f = $this->resolveFilters($request);
        $series = $this->dailySeries($f['from'], $f['to'], $hits->hitsPerDay($f['area'], $f['from'], $f['until']));

        return $this->render('usage_stats/index.html.twig', $this->commonViewData($f, $hits) + [
            'series' => $series,
        ]);
    }

    #[Route('/screens', name: 'usage_stats_screens', methods: ['GET'])]
    public function screens(Request $request, UsageHitRepository $hits): Response
    {
        $f = $this->resolveFilters($request);

        return $this->render('usage_stats/screens.html.twig', $this->commonViewData($f, $hits) + [
            'topRoutes' => $hits->topRoutes($f['area'], $f['from'], $f['until']),
        ]);
    }

    #[Route('/errors', name: 'usage_stats_errors', methods: ['GET'])]
    public function errors(Request $request, UsageHitRepository $hits): Response
    {
        $f = $this->resolveFilters($request);

        return $this->render('usage_stats/errors.html.twig', $this->commonViewData($f, $hits) + [
            'errors' => $hits->errorsByRoute($f['area'], $f['from'], $f['until']),
        ]);
    }

    /**
     * Resuelve los filtros de la query (área + rango) por whitelist y con
     * defaults seguros. Devuelve el rango ya normalizado: `from`/`to` a
     * medianoche y `until` = día siguiente a `to` (límite superior exclusivo).
     *
     * @return array{area: string, from: \DateTimeImmutable, to: \DateTimeImmutable, until: \DateTimeImmutable}
     */
    private function resolveFilters(Request $request): array
    {
        $area = $request->query->getString('area', UsageHit::AREA_GESTION);
        if (!array_key_exists($area, self::AREAS)) {
            $area = UsageHit::AREA_GESTION;
        }

        $today = new \DateTimeImmutable('today');
        $to = $this->parseDate($request->query->getString('to'), $today);
        $from = $this->parseDate($request->query->getString('from'), $today->modify('-29 days'));

        // Normaliza: from nunca después de to, y rango acotado a MAX_DAYS.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $earliest = $to->modify(sprintf('-%d days', self::MAX_DAYS));
        if ($from < $earliest) {
            $from = $earliest;
        }

        return [
            'area' => $area,
            'from' => $from,
            'to' => $to,
            'until' => $to->modify('+1 day'),
        ];
    }

    /**
     * Parsea una fecha YYYY-MM-DD a medianoche; devuelve $fallback si está
     * vacía o no es válida. El '!' resetea la hora a 00:00.
     */
    private function parseDate(string $value, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        if ($value === '') {
            return $fallback;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof \DateTimeImmutable ? $parsed : $fallback;
    }

    /**
     * Datos comunes a las tres sub-pantallas: catálogo de áreas, atajos de rango
     * con sus fechas ya calculadas, filtros activos y KPIs del rango.
     *
     * @param array{area: string, from: \DateTimeImmutable, to: \DateTimeImmutable, until: \DateTimeImmutable} $f
     * @return array<string, mixed>
     */
    private function commonViewData(array $f, UsageHitRepository $hits): array
    {
        $today = new \DateTimeImmutable('today');
        $presets = [];
        foreach (self::PRESETS as $days => $label) {
            $presets[] = [
                'label' => $label,
                'from' => $today->modify(sprintf('-%d days', $days - 1))->format('Y-m-d'),
                'to' => $today->format('Y-m-d'),
            ];
        }

        return [
            'areas' => self::AREAS,
            'presets' => $presets,
            'area' => $f['area'],
            'from' => $f['from']->format('Y-m-d'),
            'to' => $f['to']->format('Y-m-d'),
            'summary' => $hits->summary($f['area'], $f['from'], $f['until']),
        ];
    }

    /**
     * Rellena la serie diaria día a día desde $from hasta $to (ambos
     * inclusive), poniendo a cero los días sin tráfico para que el gráfico no
     * "salte" huecos.
     *
     * @param array<int, array{day: string, hits: int, sessions: int, errors: int}> $rows
     * @return array<int, array{label: string, hits: int, sessions: int, errors: int}>
     */
    private function dailySeries(\DateTimeImmutable $from, \DateTimeImmutable $to, array $rows): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['day']] = $r;
        }

        $series = [];
        $period = new \DatePeriod($from, new \DateInterval('P1D'), $to->modify('+1 day'));
        foreach ($period as $day) {
            $key = $day->format('Y-m-d');
            $series[] = [
                'label' => $day->format('d/m'),
                'hits' => $byDay[$key]['hits'] ?? 0,
                'sessions' => $byDay[$key]['sessions'] ?? 0,
                'errors' => $byDay[$key]['errors'] ?? 0,
            ];
        }

        return $series;
    }
}
