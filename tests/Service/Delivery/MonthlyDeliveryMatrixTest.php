<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Node;
use App\Service\Delivery\MonthlyDeliveryMatrix;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del pivote mensual: a partir de las hojas semanales (ya con forma de
 * {@see \App\Service\Delivery\NodeDeliverySheet}) verifica que cada socio sale en
 * una sola fila con una celda por semana, que un quincenal queda en blanco las
 * semanas que no le tocan, los subtotales por grupo y semana, y el orden y las
 * secciones (compartidas al final).
 */
class MonthlyDeliveryMatrixTest extends TestCase
{
    private MonthlyDeliveryMatrix $matrix;

    protected function setUp(): void
    {
        $this->matrix = new MonthlyDeliveryMatrix();
    }

    public function testPivotaSocioPorSemanaYAlineaQuincenal(): void
    {
        $result = $this->matrix->build([
            // Semana A (5-jun): Cris (semanal) + Eva (quincenal) recogen.
            $this->week('2026-06-05', [
                $this->torremocha([
                    ['Semanales', [$this->row('Cris', 'S', 1.0, 1)]],
                    ['Quincenales y mensuales', [$this->row('Eva', 'Q', 1.0, 2)]],
                ], subtotalCestas: 2.0),
            ]),
            // Semana B (12-jun): solo Cris (a Eva, quincenal, no le toca).
            $this->week('2026-06-12', [
                $this->torremocha([
                    ['Semanales', [$this->row('Cris', 'S', 1.0, 1)]],
                ], subtotalCestas: 1.0),
            ]),
        ]);

        $this->assertCount(2, $result['weeks'], 'dos viernes en columnas');
        $this->assertCount(1, $result['nodes']);

        $node = $result['nodes'][0];
        $this->assertSame('Sierra', $node['name']);
        $this->assertCount(1, $node['groups']);

        $group = $node['groups'][0];
        $this->assertSame('Torremocha', $group['name']);
        $this->assertTrue($group['multi_mod'], 'hay semanales y quincenales → se muestran sub-etiquetas');
        $this->assertSame(['Semanales', 'Quincenales y mensuales'], array_column($group['modalities'], 'label'));

        // Cris (semanal): una sola fila (mismo partner_id las dos semanas), celda en ambas.
        $cris = $group['modalities'][0]['rows'][0];
        $this->assertSame('Cris', $cris['name']);
        $this->assertNotNull($cris['cells'][0]);
        $this->assertNotNull($cris['cells'][1]);
        $this->assertSame(1.0, $cris['cells'][0]['cestas']);

        // Eva (quincenal): fila presente, pero la semana B en blanco.
        $eva = $group['modalities'][1]['rows'][0];
        $this->assertSame('Eva', $eva['name']);
        $this->assertNotNull($eva['cells'][0]);
        $this->assertNull($eva['cells'][1], 'la semana que no le toca queda vacía');

        // Subtotales por semana del grupo: A=2 cestas, B=1.
        $this->assertSame(2.0, $group['subtotals'][0]['cestas']);
        $this->assertSame(1.0, $group['subtotals'][1]['cestas']);
    }

    public function testCompartidasVanEnGrupoPropioAlFinal(): void
    {
        $result = $this->matrix->build([
            $this->week('2026-06-05', [
                [
                    'node' => $this->node('Sierra'),
                    'sheet' => [
                        'groups' => [$this->group('Torremocha', [
                            ['Semanales', [$this->row('Cris', 'S', 1.0, 1)]],
                        ], 1.0)],
                        'shared' => [
                            'rows' => [
                                $this->row('Ana', 'SC', 0.5, 10),
                                $this->row('Bea', 'SC', 0.5, 11),
                            ],
                            'subtotal_cestas' => 1.0,
                            'subtotal_eggs' => null,
                        ],
                        'totals' => ['cestas' => 2.0, 'docenas' => 0.0],
                    ],
                ],
            ]),
        ]);

        $groups = $result['nodes'][0]['groups'];
        $this->assertSame(['Torremocha', MonthlyDeliveryMatrix::SHARED_GROUP_NAME], array_column($groups, 'name'));
        $this->assertTrue($groups[1]['shared'], 'el grupo de compartidas va marcado y al final');
        $this->assertCount(2, $groups[1]['modalities'][0]['rows']);
    }

    public function testSocioQueAparecePorPrimeraVezEnSemanaTardiaTieneCeldaPreviaVacia(): void
    {
        $result = $this->matrix->build([
            $this->week('2026-06-05', [
                $this->torremocha([['Semanales', [$this->row('Cris', 'S', 1.0, 1)]]], 1.0),
            ]),
            $this->week('2026-06-12', [
                $this->torremocha([['Semanales', [
                    $this->row('Cris', 'S', 1.0, 1),
                    $this->row('Nuevo', 'S', 1.0, 9),
                ]]], 2.0),
            ]),
        ]);

        $rows = $result['nodes'][0]['groups'][0]['modalities'][0]['rows'];
        $nuevo = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === 'Nuevo'))[0];
        $this->assertNull($nuevo['cells'][0], 'la semana anterior a su alta queda vacía');
        $this->assertNotNull($nuevo['cells'][1]);
    }

    public function testTotalesPorNodoYGranTotalPorSemana(): void
    {
        $result = $this->matrix->build([
            // Semana A: reparten Madrid (4 cestas, 1 docena) y Sierra (3 cestas, 2½ doc).
            $this->week('2026-06-05', [
                $this->sheetFor('Madrid', 'Centro', [$this->row('Leo', 'S', 1.0, 2)], 4.0, 1.0),
                $this->sheetFor('Sierra', 'Torremocha', [$this->row('Cris', 'S', 1.0, 1)], 3.0, 2.5),
            ]),
            // Semana B: solo Sierra (2 cestas, ½ docena); Madrid no reparte.
            $this->week('2026-06-12', [
                $this->sheetFor('Sierra', 'Torremocha', [$this->row('Cris', 'S', 1.0, 1)], 2.0, 0.5),
            ]),
        ]);

        $byName = [];
        foreach ($result['nodes'] as $node) {
            $byName[$node['name']] = $node;
        }

        // Total por nodo y semana (cestas, docenas).
        $this->assertSame(['cestas' => 3.0, 'docenas' => 2.5], $byName['Sierra']['totals'][0]);
        $this->assertSame(['cestas' => 2.0, 'docenas' => 0.5], $byName['Sierra']['totals'][1]);
        $this->assertSame(['cestas' => 4.0, 'docenas' => 1.0], $byName['Madrid']['totals'][0]);
        $this->assertNull($byName['Madrid']['totals'][1], 'la semana que el nodo no reparte queda sin total');

        // Gran total por semana: suma de todos los nodos (la cifra del pedido de huevos).
        $this->assertSame(['cestas' => 7.0, 'docenas' => 3.5], $result['grand_totals'][0]);
        $this->assertSame(['cestas' => 2.0, 'docenas' => 0.5], $result['grand_totals'][1]);
    }

    // --- helpers de construcción ---

    /** Una hoja de nodo con un único grupo y su total (cestas, docenas) explícito. */
    private function sheetFor(string $nodeName, string $groupName, array $rows, float $cestas, float $docenas): array
    {
        return [
            'node' => $this->node($nodeName),
            'sheet' => [
                'groups' => [$this->group($groupName, [['Semanales', $rows]], $cestas)],
                'shared' => ['rows' => [], 'subtotal_cestas' => 0.0, 'subtotal_eggs' => null],
                'totals' => ['cestas' => $cestas, 'docenas' => $docenas],
            ],
        ];
    }

    /** @param list<array{node:Node, sheet:array}> $nodes */
    private function week(string $date, array $nodes): array
    {
        return ['date' => new \DateTimeImmutable($date), 'nodes' => $nodes];
    }

    /** Nodo "Sierra" con un único grupo "Torremocha". */
    private function torremocha(array $modalities, float $subtotalCestas): array
    {
        return [
            'node' => $this->node('Sierra'),
            'sheet' => [
                'groups' => [$this->group('Torremocha', $modalities, $subtotalCestas)],
                'shared' => ['rows' => [], 'subtotal_cestas' => 0.0, 'subtotal_eggs' => null],
                'totals' => ['cestas' => $subtotalCestas, 'docenas' => 0.0],
            ],
        ];
    }

    /** @param list<array{0:string, 1:list<array>}> $modalities pares [label, rows] */
    private function group(string $name, array $modalities, float $subtotalCestas): array
    {
        return [
            'name' => $name,
            'color' => '#cfe',
            'locality' => $name,
            'subtotal_cestas' => $subtotalCestas,
            'subtotal_eggs' => null,
            'modalities' => array_map(
                static fn (array $m): array => ['label' => $m[0], 'rows' => $m[1]],
                $modalities,
            ),
        ];
    }

    private function row(string $name, string $code, float $cestas, int $partnerId): array
    {
        return [
            'name' => $name,
            'code' => $code,
            'cestas' => $cestas,
            'egg_spec' => null,
            'egg_count' => 0,
            'locality' => 'Torremocha',
            'relocated_from' => null,
            'partner_id' => $partnerId,
        ];
    }

    private function node(string $name): Node
    {
        $node = $this->createStub(Node::class);
        $node->method('getName')->willReturn($name);

        return $node;
    }
}
