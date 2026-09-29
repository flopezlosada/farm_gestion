<?php

namespace App\Tests\Service\Delivery;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Prueba de RENDER del listado mensual imprimible. dompdf sólo repite en cada
 * página la cabecera que va en el <thead> de la tabla que se parte, así que lo
 * que se fija aquí es la estructura que lo hace posible: una tabla por nodo con
 * el título, las fechas y CESTA/HUEVOS en su <thead>, y grupos que no se parten
 * entre páginas porque todas sus filas menos el subtotal piden no cortar después.
 */
class MonthlyPrintableRenderTest extends KernelTestCase
{
    private const TEMPLATE = 'delivery/printable_monthly.html.twig';

    public function testCadaNodoLlevaFechasYCestaHuevosEnSuThead(): void
    {
        $crawler = $this->render();

        // La hoja de resumen del final también es un .node-page, con su propia tabla.
        $this->assertCount(2, $crawler->filter('.node-page > table:not(.summary)'), 'Una tabla por nodo.');
        $this->assertCount(2, $crawler->filter('.node-page > table > thead'));

        $thead = $crawler->filter('.node-page > table > thead')->first();
        $this->assertStringContainsString('Torremocha', $thead->text());
        $this->assertStringContainsString('VIERNES 2 OCTUBRE', $thead->text());
        $this->assertStringContainsString('VIERNES 9 OCTUBRE', $thead->text());
        $this->assertCount(2, $thead->filter('.hd-c'), 'Un CESTA por semana.');
        $this->assertCount(2, $thead->filter('.hd-h'), 'Un HUEVOS por semana.');
    }

    public function testUnGrupoSoloPuedeCortarseTrasSuSubtotal(): void
    {
        $crawler = $this->render();

        // Primer nodo: grupo normal, grupo de compartidas y fila de total.
        $bodies = $crawler->filter('.node-page')->first()->filter('table > tbody');
        $this->assertCount(3, $bodies);

        foreach ([0, 1] as $i) {
            $rows = $bodies->eq($i)->filter('tr');
            $this->assertGreaterThan(2, $rows->count());
            $rows->slice(0, $rows->count() - 1)->each(
                fn (Crawler $tr) => $this->assertContains('keep', explode(' ', (string) $tr->attr('class'))),
            );
            $this->assertStringContainsString('sub', (string) $rows->last()->attr('class'));
            $this->assertStringNotContainsString('keep', (string) $rows->last()->attr('class'), 'Tras el subtotal sí se puede cortar.');
        }
    }

    /**
     * Renderiza el listado con dos nodos y dos viernes. El primero con un grupo
     * de dos modalidades (fila de subapartado incluida) y una pareja compartida;
     * celdas vacías (null) como las de una semana en la que la socia no recoge.
     */
    private function render(): Crawler
    {
        $weeks = [
            ['date' => new \DateTimeImmutable('2026-10-02')],
            ['date' => new \DateTimeImmutable('2026-10-09')],
        ];
        $cell = ['cestas' => 1, 'egg_spec' => '1D', 'egg_count' => 12];
        $row = fn (string $name, bool $pairEnd = false) => [
            'color' => '#9bc2e6', 'locality' => 'TORREMOCHA', 'name' => $name, 'code' => 'SH',
            'cells' => [$cell, null], 'pair_end' => $pairEnd,
        ];
        $subtotals = [['cestas' => 2, 'egg_spec' => '2D'], null];
        $totals = [['cestas' => 4, 'docenas' => 4], null];

        $matrix = [
            'weeks' => $weeks,
            'nodes' => [
                [
                    'name' => 'Torremocha',
                    'groups' => [
                        [
                            'name' => 'La Cabrera', 'shared' => false, 'multi_mod' => true,
                            'modalities' => [
                                ['label' => 'Semanales', 'rows' => [$row('Ana'), $row('Luis')]],
                                ['label' => 'Quincenales', 'rows' => [$row('Eva')]],
                            ],
                            'subtotals' => $subtotals,
                        ],
                        [
                            'name' => 'Compartidas', 'shared' => true, 'multi_mod' => false,
                            'modalities' => [['label' => null, 'rows' => [$row('Rosa'), $row('Juan', true)]]],
                            'subtotals' => $subtotals,
                        ],
                    ],
                    'totals' => $totals,
                ],
                [
                    'name' => 'Cascorro',
                    'groups' => [[
                        'name' => 'Cascorro', 'shared' => false, 'multi_mod' => false,
                        'modalities' => [['label' => null, 'rows' => [$row('Marta')]]],
                        'subtotals' => $subtotals,
                    ]],
                    'totals' => $totals,
                ],
            ],
            'grand_totals' => [['cestas' => 8, 'docenas' => 8], ['cestas' => 0, 'docenas' => 0]],
            'grand_total_month' => ['cestas' => 8, 'docenas' => 8],
        ];

        $html = static::getContainer()->get(Environment::class)->render(self::TEMPLATE, [
            'matrix' => $matrix,
            'year' => 2026,
            'month' => 10,
        ]);

        return new Crawler($html);
    }
}
