<?php

namespace App\Service\Hosting;

use App\Entity\Stay;
use App\Repository\StayRepository;

/**
 * Métricas del albergue agregadas por procedencia ({@see \App\Entity\HelperSource}):
 * cuántos voluntarios, cuántas estancias y cuántas noches por cada origen
 * (WWOOF, Workaway, prácticas...) en un año. Sirve para ver de dónde llega la
 * gente y cuánto se queda.
 *
 * Cancela el ruido: cuentan confirmadas y solicitadas (las canceladas no), y las
 * NOCHES sólo de las confirmadas (lo que de verdad se durmió). Un voluntario se
 * cuenta una vez por procedencia aunque tenga varias estancias.
 */
final class HostingStatsBuilder
{
    public function __construct(
        private readonly StayRepository $stayRepository,
    ) {
    }

    /**
     * Métricas por procedencia del año dado, ordenadas por nombre de procedencia,
     * más una fila de totales.
     *
     * @param int $year
     * @return array{
     *     year: int,
     *     rows: list<array{source: \App\Entity\HelperSource, helpers: int, stays: int, nights: int}>,
     *     totals: array{helpers: int, stays: int, nights: int}
     * }
     */
    public function bySource(int $year): array
    {
        /** @var array<int, array{source: \App\Entity\HelperSource, helper_ids: array<int, true>, stays: int, nights: int}> $acc */
        $acc = [];

        foreach ($this->stayRepository->findByArrivalYear($year) as $stay) {
            $source = $stay->getHelper()->getSource();
            $sourceId = $source->getId();
            if (!isset($acc[$sourceId])) {
                $acc[$sourceId] = ['source' => $source, 'helper_ids' => [], 'stays' => 0, 'nights' => 0];
            }

            $acc[$sourceId]['helper_ids'][$stay->getHelper()->getId()] = true;
            $acc[$sourceId]['stays']++;
            if ($stay->occupiesCapacity()) {
                $acc[$sourceId]['nights'] += $this->nights($stay);
            }
        }

        $rows = [];
        $totals = ['helpers' => 0, 'stays' => 0, 'nights' => 0];
        foreach ($acc as $entry) {
            $helpers = count($entry['helper_ids']);
            $rows[] = [
                'source' => $entry['source'],
                'helpers' => $helpers,
                'stays' => $entry['stays'],
                'nights' => $entry['nights'],
            ];
            $totals['helpers'] += $helpers;
            $totals['stays'] += $entry['stays'];
            $totals['nights'] += $entry['nights'];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['source']->getName(), (string) $b['source']->getName()));

        return ['year' => $year, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * Noches de una estancia (días entre llegada y salida; el intervalo es
     * medio-abierto, así que es exactamente el nº de noches dormidas).
     *
     * @param Stay $stay
     * @return int
     */
    private function nights(Stay $stay): int
    {
        return (int) $stay->getArrivalDate()->diff($stay->getDepartureDate())->format('%a');
    }
}
