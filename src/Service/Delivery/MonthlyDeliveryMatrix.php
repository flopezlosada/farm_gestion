<?php

namespace App\Service\Delivery;

/**
 * Pivota los listados de reparto SEMANALES de un mes (uno por viernes, ya
 * construidos por {@see NodeDeliverySheet}) a una MATRIZ mensual: los socios en
 * filas (agrupados por nodo → grupo de recogida → modalidad, igual que el
 * semanal) y los viernes del mes en columnas. Cada celda dice si ese socio lleva
 * cesta ese viernes y su especificación de huevos; vacía si esa semana no le
 * toca (un quincenal sale en semanas alternas, un mensual en una sola).
 *
 * Es el mismo dato que el listado semanal, sólo que transpuesto: reutiliza la
 * agrupación/orden que ya calculó {@see NodeDeliverySheet} por semana y lo único
 * que añade aquí es la UNIÓN de socios entre semanas (alineados por partner_id) y
 * el subtotal por grupo y semana.
 *
 * Shaper PURO: no toca la BBDD. El controlador alimenta las hojas semanales (de
 * piedra o de dibujo) y este servicio sólo da forma.
 */
class MonthlyDeliveryMatrix
{
    /** Orden de los códigos dentro de un grupo (mismo criterio que el semanal). */
    private const CODE_ORDER = [
        'S' => 0, 'SH' => 1, 'Q' => 2, 'QH' => 3, 'M' => 4, 'MH' => 5,
        'SC' => 6, 'SCH' => 7, 'QC' => 8, 'QCH' => 9, 'MC' => 10, 'MCH' => 11,
        'H' => 12,
    ];

    /** Nombre del grupo sintético de compartidas dentro de cada nodo. */
    public const SHARED_GROUP_NAME = 'Compartidas';

    /**
     * @param list<array{date:\DateTimeInterface, nodes: list<array{node:\App\Entity\Node, sheet:array}>}> $weeks
     *        Una entrada por viernes del mes, en orden ascendente. `sheet` es la
     *        estructura que devuelve {@see NodeDeliverySheet::build}/`shape`.
     *
     * @return array{
     *   weeks: list<array{date:\DateTimeInterface}>,
     *   nodes: list<array{
     *     name:string,
     *     groups: list<array{
     *       name:string, color:?string, shared:bool, multi_mod:bool,
     *       modalities: list<array{label:?string, rows: list<array{name:string, code:string, locality:string, color:?string, cells: list<?array{cestas:float, egg_spec:?string, egg_count:int}>}>}>,
     *       subtotals: list<?array{cestas:float, egg_spec:?string}>
     *     }>,
     *     totals: list<?array{cestas:float, docenas:float}>
     *   }>,
     *   grand_totals: list<array{cestas:float, docenas:float}>,
     *   grand_total_month: array{cestas:float, docenas:float}
     * }
     */
    public function build(array $weeks): array
    {
        $weekCount = count($weeks);
        $nodes = []; // nodeName => accumulator
        // Total por semana de TODO el reparto (todos los nodos): la cifra que sirve
        // para el pedido de huevos de cada viernes. Se va sumando aquí y sale como
        // resumen del mes, además del total por nodo (que ya trae cada hoja semanal).
        $grandTotals = array_fill(0, $weekCount, ['cestas' => 0.0, 'docenas' => 0.0]);

        foreach (array_values($weeks) as $wi => $week) {
            foreach ($week['nodes'] as $ns) {
                $nodeName = $ns['node']->getName() ?? '';
                $nodes[$nodeName] ??= [
                    'name' => $nodeName,
                    'groups' => [],
                    'totals' => array_fill(0, $weekCount, null),
                ];

                foreach ($ns['sheet']['groups'] ?? [] as $group) {
                    $this->accumulateGroup($nodes[$nodeName]['groups'], $group, false, $wi, $weekCount);
                }

                $shared = $ns['sheet']['shared'] ?? null;
                if ($shared !== null && ($shared['rows'] ?? []) !== []) {
                    $this->accumulateShared($nodes[$nodeName]['groups'], $shared, $wi, $weekCount);
                }

                // Total del nodo esa semana (cestas físicas + docenas de huevos), tal
                // como ya lo calcula NodeDeliverySheet::computeTotals para el semanal.
                $t = $ns['sheet']['totals'] ?? null;
                if ($t !== null) {
                    $cestas = (float) ($t['cestas'] ?? 0.0);
                    $docenas = (float) ($t['docenas'] ?? 0.0);
                    $nodes[$nodeName]['totals'][$wi] = ['cestas' => $cestas, 'docenas' => $docenas];
                    $grandTotals[$wi]['cestas'] += $cestas;
                    $grandTotals[$wi]['docenas'] += $docenas;
                }
            }
        }

        // Total del mes entero (suma de todas las semanas): volumen mensual de
        // cestas y docenas, pie del resumen del pedido de huevos.
        $grandTotalMonth = ['cestas' => 0.0, 'docenas' => 0.0];
        foreach ($grandTotals as $g) {
            $grandTotalMonth['cestas'] += $g['cestas'];
            $grandTotalMonth['docenas'] += $g['docenas'];
        }

        return [
            'weeks' => array_map(static fn (array $w): array => ['date' => $w['date']], array_values($weeks)),
            'nodes' => $this->finalizeNodes($nodes),
            'grand_totals' => $grandTotals,
            'grand_total_month' => $grandTotalMonth,
        ];
    }

    /**
     * Vuelca un grupo de la hoja semanal (WBG, o sección Trasladados/Extra/
     * Albergue) al acumulador del nodo, casando cada socio con su fila por
     * partner_id y rellenando la celda de la semana `$wi`.
     *
     * @param array<string,array> $groups acumulador por nombre de grupo (por referencia)
     */
    private function accumulateGroup(array &$groups, array $group, bool $shared, int $wi, int $weekCount): void
    {
        $name = $group['name'] ?? '';
        $groups[$name] ??= [
            'name' => $name,
            'color' => $group['color'] ?? null,
            'shared' => $shared,
            'mods' => [],         // label => ['label'=>, 'order'=>, 'rows'=> key => row]
            'subtotals' => array_fill(0, $weekCount, null),
        ];

        $groups[$name]['subtotals'][$wi] = [
            'cestas' => (float) ($group['subtotal_cestas'] ?? 0.0),
            'egg_spec' => $group['subtotal_eggs'] ?? null,
        ];

        foreach ($group['modalities'] ?? [] as $mod) {
            $label = $mod['label'] ?? null;
            $key = $label ?? '';
            $groups[$name]['mods'][$key] ??= [
                'label' => $label,
                'order' => count($groups[$name]['mods']),
                'rows' => [],
            ];
            foreach ($mod['rows'] ?? [] as $row) {
                $this->placeRow($groups[$name]['mods'][$key]['rows'], $row, $group['color'] ?? null, $wi, $weekCount);
            }
        }
    }

    /**
     * Bloque de compartidas del nodo como grupo sintético "Compartidas" (cada
     * fila lleva su propio color). Una sola modalidad sin etiqueta.
     *
     * @param array<string,array> $groups acumulador por nombre de grupo (por referencia)
     */
    private function accumulateShared(array &$groups, array $shared, int $wi, int $weekCount): void
    {
        $this->accumulateGroup($groups, [
            'name' => self::SHARED_GROUP_NAME,
            'color' => null,
            'subtotal_cestas' => $shared['subtotal_cestas'] ?? 0.0,
            'subtotal_eggs' => $shared['subtotal_eggs'] ?? null,
            'modalities' => [['label' => null, 'rows' => $shared['rows']]],
        ], true, $wi, $weekCount);
    }

    /**
     * Coloca una fila de socio en el acumulador de su modalidad: la crea la
     * primera semana que aparece y, en las siguientes, sólo rellena la celda.
     *
     * @param array<string,array> $rows acumulador por clave de fila (por referencia)
     */
    private function placeRow(array &$rows, array $row, ?string $groupColor, int $wi, int $weekCount): void
    {
        // Clave estable del socio entre semanas: partner_id si lo hay; si no
        // (extra de no-suscriptor, voluntario del albergue) por nombre+código.
        $key = $row['partner_id'] !== null
            ? 'p:' . $row['partner_id']
            : 'n:' . ($row['name'] ?? '') . '|' . ($row['code'] ?? '');

        $rows[$key] ??= [
            'name' => $row['name'] ?? '',
            'code' => $row['code'] ?? '',
            'locality' => $row['locality'] ?? '',
            'color' => $row['color'] ?? $groupColor,
            'cells' => array_fill(0, $weekCount, null),
        ];

        $rows[$key]['cells'][$wi] = [
            'cestas' => (float) ($row['cestas'] ?? 0.0),
            'egg_spec' => $row['egg_spec'] ?? null,
            'egg_count' => (int) ($row['egg_count'] ?? 0),
        ];
    }

    /**
     * Cierra los acumuladores: ordena nodos por nombre, grupos por nombre (con
     * Compartidas y las secciones especiales al final), modalidades por su orden
     * de aparición y filas por código y nombre.
     *
     * @param array<string,array> $nodes
     * @return list<array>
     */
    private function finalizeNodes(array $nodes): array
    {
        uasort($nodes, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $out = [];
        foreach ($nodes as $node) {
            $groups = array_values(array_map($this->finalizeGroup(...), $node['groups']));
            usort($groups, $this->compareGroups(...));
            $out[] = ['name' => $node['name'], 'groups' => $groups, 'totals' => $node['totals']];
        }

        return $out;
    }

    /**
     * @return array{name:string, color:?string, shared:bool, multi_mod:bool, modalities:list<array>, subtotals:list<?array>}
     */
    private function finalizeGroup(array $group): array
    {
        $mods = array_values($group['mods']);
        usort($mods, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        // El grupo de compartidas NO se reordena: sus filas ya llegan con las
        // parejas (Partner.sharePartner) pegadas desde NodeDeliverySheet::buildShared,
        // y reordenar por código+nombre las separaría. Se conserva el orden de
        // inserción, que es fiable porque los dos hogares de una misma cesta
        // comparten calendario de reparto (aparecen las mismas semanas, y por
        // tanto entran juntos y emparejados en el acumulador).
        $shared = $group['shared'];
        $modalities = array_map(function (array $mod) use ($shared): array {
            $rows = array_values($mod['rows']);
            if (!$shared) {
                usort($rows, $this->compareRows(...));
            }

            return ['label' => $mod['label'], 'rows' => $rows];
        }, $mods);

        return [
            'name' => $group['name'],
            'color' => $group['color'],
            'shared' => $group['shared'],
            'multi_mod' => count($modalities) > 1,
            'modalities' => $modalities,
            'subtotals' => $group['subtotals'],
        ];
    }

    /** Grupos por nombre, pero las secciones especiales (Compartidas, Trasladados, Cestas extra, Albergue) al final. */
    private function compareGroups(array $a, array $b): int
    {
        $wa = $this->groupWeight($a['name'], $a['shared']);
        $wb = $this->groupWeight($b['name'], $b['shared']);

        return $wa <=> $wb ?: strnatcasecmp($a['name'], $b['name']);
    }

    private function groupWeight(string $name, bool $shared): int
    {
        if ($shared) {
            return 1;
        }

        return in_array($name, ['Trasladados', 'Cestas extra', 'Albergue'], true) ? 2 : 0;
    }

    /** Filas por código de modalidad (orden explícito) y, a igualdad, por nombre. */
    private function compareRows(array $a, array $b): int
    {
        $oa = self::CODE_ORDER[$a['code']] ?? 99;
        $ob = self::CODE_ORDER[$b['code']] ?? 99;

        return $oa <=> $ob ?: strnatcasecmp($a['name'], $b['name']);
    }
}
