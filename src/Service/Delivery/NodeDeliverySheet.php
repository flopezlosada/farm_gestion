<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WeeklyBasketItemRepository;
use App\Repository\WeeklyBasketRepository;

/**
 * Construye la estructura del listado de reparto imprimible para un Nodo en un
 * día (Basket) concreto. Es el mismo dominio que la pantalla v2
 * ({@see \App\Controller\DeliveryController::byNode}) pero volcado al formato
 * papel: grupos de recogida (WeeklyBasketGroup), dentro ordenados por modalidad,
 * con subtotal de cestas por grupo; las cestas compartidas en un bloque aparte
 * con las parejas pegadas; y los totales del nodo.
 *
 * El shaper ({@see shape}) es PURO: opera sobre una lista de {@see DeliveryLine}
 * y no toca la BBDD. Hay dos alimentadores que producen esas líneas:
 *  - {@see build}: camino MATERIALIZADO — mapea los WeeklyBasket persistidos de
 *    una semana ya en piedra. Si el día no está materializado, la hoja sale vacía.
 *  - la PROYECCIÓN (semana futura dibujada al vuelo) alimenta {@see shape}
 *    directamente con líneas calculadas desde las reglas, sin persistir.
 *
 * TODO(DRY): {@see DeliveryController} tiene su propia versión de pinSharedPairs,
 * compareByDisplayName y el cálculo de totales. Convergerlas en un follow-up
 * (haciendo que byNode delegue aquí) para no duplicar la lógica sutil de pegado
 * de parejas. Se deja duplicado a propósito en esta tanda para no desestabilizar
 * la pantalla v2 ya validada.
 */
class NodeDeliverySheet
{
    /** IDs de BasketShare que son cesta compartida (media cesta entre dos hogares). */
    private const SHARED_BASKET_SHARE_IDS = [4, 6, 7];

    /** Letra de código por modalidad, tal como aparece en el listado en papel. */
    private const CODE_BY_BASKET_SHARE = [
        1 => 'S',   // Semanal
        2 => 'Q',   // Quincenal
        3 => 'M',   // Mensual
        4 => 'SC',  // Semanal compartida
        5 => 'H',   // Solo huevos
        6 => 'QC',  // Quincenal compartida
        7 => 'MC',  // Mensual compartida
    ];

    public function __construct(
        private readonly WeeklyBasketRepository $weeklyBasketRepo,
        private readonly WeeklyBasketItemRepository $weeklyBasketItemRepo,
        private readonly PartnerBasketShareRepository $partnerBasketShareRepo,
        private readonly HelperDeliveryResolver $helperDeliveryResolver,
    ) {
    }

    /**
     * Estructura imprimible del nodo en el día dado, a partir de los
     * WeeklyBasket YA materializados (camino de piedra).
     *
     * @return array{
     *   groups: list<array{name:string, color:?string, locality:string, subtotal_cestas:float, subtotal_eggs:?string, modalities: list<array{label:string, rows: list<array<string,mixed>>}>}>,
     *   shared: array{rows: list<array<string,mixed>>, subtotal_cestas: float, subtotal_eggs: ?string},
     *   totals: array{cestas:float, docenas:float}
     * }
     */
    public function build(Node $node, Basket $basket): array
    {
        return $this->shape(array_merge(
            $this->linesFromMaterialized($node, $basket),
            $this->helperLines($node, $basket),
        ));
    }

    /**
     * Líneas de entrega de los voluntarios del albergue que recogen en este
     * nodo+día (cesta derivada, no materializada). El camino de PIEDRA las
     * mezcla dentro de {@see build}; el camino de DIBUJO (proyección) debe
     * anexarlas a las líneas proyectadas antes de {@see shape}, por eso se
     * expone aquí en vez de esconderlas en build().
     *
     * @param Node   $node
     * @param Basket $basket
     * @return DeliveryLine[]
     */
    public function helperLines(Node $node, Basket $basket): array
    {
        return $this->helperDeliveryResolver->forNodeAndBasket($node, $basket);
    }

    /**
     * Da forma al listado a partir de una lista de líneas de entrega, vengan de
     * piedra (materializadas) o de dibujo (proyección). Shaper PURO.
     *
     * @param DeliveryLine[] $lines
     * @return array{
     *   groups: list<array{name:string, color:?string, locality:string, subtotal_cestas:float, subtotal_eggs:?string, modalities: list<array{label:string, rows: list<array<string,mixed>>}>}>,
     *   shared: array{rows: list<array<string,mixed>>, subtotal_cestas: float, subtotal_eggs: ?string},
     *   totals: array{cestas:float, docenas:float}
     * }
     */
    public function shape(array $lines): array
    {
        // Partición: voluntarios del albergue (sección propia, sin modalidad pero
        // NO son "cestas extra"), compartidas (bloque aparte), cestas extra de
        // no-suscriptores (sin modalidad → sección propia al final) y resto (por
        // grupo). Los voluntarios se apartan ANTES del check de modalidad null,
        // que si no caerían en "Cestas extra".
        $helper = [];
        $shared = [];
        $extra = [];
        $regular = [];
        foreach ($lines as $line) {
            if ($line->isHelper) {
                $helper[] = $line;
                continue;
            }
            if ($line->basketShareId === null) {
                $extra[] = $line; // entrega "solo extra" sin modalidad
                continue;
            }
            if (in_array($line->basketShareId, self::SHARED_BASKET_SHARE_IDS, true)) {
                $shared[] = $line;
            } else {
                $regular[] = $line;
            }
        }

        $groups = $this->buildGroups($regular);
        if ($extra !== []) {
            $groups[] = $this->buildExtraGroup($extra);
        }
        if ($helper !== []) {
            $groups[] = $this->buildHelperGroup($helper);
        }

        return [
            'groups' => $groups,
            'shared' => $this->buildShared($shared),
            'totals' => $this->computeTotals($lines),
        ];
    }

    /**
     * Mapea los WeeklyBasket materializados de un nodo+día a líneas de entrega.
     * Es el adaptador del camino de piedra: lee de los repositorios las
     * cantidades y el PBS activo de cada entrega y los vuelca a {@see DeliveryLine}.
     *
     * @return DeliveryLine[]
     */
    private function linesFromMaterialized(Node $node, Basket $basket): array
    {
        $weeklyBaskets = $this->weeklyBasketRepo->findForNodeAndBasket($node, $basket);

        // Cestas físicas (verdura) y docenas (huevos) ya materializadas por
        // entrega: la ponderación ½ de las compartidas y el 0 de "solo huevos"
        // están estampados en el ítem, así que aquí solo se leen.
        $componentAmounts = $this->weeklyBasketItemRepo->componentAmountsFor($weeklyBaskets);

        $lines = [];
        foreach ($weeklyBaskets as $wb) {
            $lines[] = $this->lineFromWeeklyBasket($wb, $componentAmounts, $basket);
        }

        return $lines;
    }

    /**
     * @param array<int,array<int,float>> $componentAmounts wb.id => [componentId => amount]
     */
    private function lineFromWeeklyBasket(WeeklyBasket $wb, array $componentAmounts, Basket $basket): DeliveryLine
    {
        $partner = $wb->getPartner();
        $wbg = $wb->getWeeklyBasketGroup();

        // PBS activo: hace falta para el código de modalidad (saber si el hogar
        // está suscrito a huevos → sufijo "H").
        $pbs = $partner !== null
            ? $this->partnerBasketShareRepo->findActiveForPartner($partner, $basket->getDate())
            : null;

        $amounts = $componentAmounts[$wb->getId()] ?? [];

        return new DeliveryLine(
            nameForDelivery: $partner?->getNameForDelivery() ?? '',
            basketShareId: $wb->getBasketShare()?->getId(),
            subscribedToEggs: $pbs?->getEggAmount() !== null,
            cestas: $amounts[BasketComponent::ID_VEGETABLES] ?? 0.0,
            dozens: $amounts[BasketComponent::ID_EGGS] ?? 0.0,
            groupId: $wbg?->getId(),
            groupName: $wbg?->getName(),
            groupColor: $wbg?->getColor(),
            city: $partner?->getCity()?->getName(),
            partnerId: $partner?->getId(),
            sharePartnerId: $partner?->getSharePartner()?->getId(),
            relocatedFromLabel: $wb->getRelocatedFromLabel(),
        );
    }

    /**
     * Grupos de recogida (WBG) en orden alfabético; dentro, subgrupos por
     * modalidad (bs.id ascendente: S, Q, M, H) con su subtotal de cestas y de
     * huevos (este último en la notación compacta D/M de las filas).
     *
     * @param DeliveryLine[] $lines
     * @return list<array{name:string, color:?string, locality:string, subtotal_cestas:float, subtotal_eggs:?string, modalities: list<array{label:string, rows: list<array<string,mixed>>}>}>
     */
    private function buildGroups(array $lines): array
    {
        $byWbg = [];
        $relocated = [];
        foreach ($lines as $line) {
            if ($line->basketShareId === null) {
                continue;
            }
            // Trasladados (override de nodo): a su propia sección al final, no al grupo
            // destino (vienen de otro nodo, son visitantes puntuales de esta semana).
            if ($line->relocatedFromLabel !== null) {
                $relocated[] = $line;
                continue;
            }
            if ($line->groupId === null) {
                continue;
            }
            $gid = $line->groupId;
            $bucket = $this->bucketFor($line->basketShareId);
            $byWbg[$gid] ??= [
                'name' => $line->groupName ?? '',
                'color' => $line->groupColor,
                'locality' => $this->localityFor($line->groupName ?? ''),
                'mods' => [],
                'subtotal_cestas' => 0.0,
                'subtotal_dozens' => 0.0,
            ];
            $byWbg[$gid]['mods'][$bucket['order']] ??= ['label' => $bucket['label'], 'rows' => []];
            $byWbg[$gid]['mods'][$bucket['order']]['rows'][] = $this->row($line);
            $byWbg[$gid]['subtotal_cestas'] += $line->cestas;
            $byWbg[$gid]['subtotal_dozens'] += $line->dozens;
        }

        uasort($byWbg, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $groups = [];
        foreach ($byWbg as $g) {
            ksort($g['mods']); // por 'order' del bucket: Semanales → Quincenales y mensuales → Solo huevos.
            foreach ($g['mods'] as &$mod) {
                // Dentro del bucket, agrupar por código completo (Q antes que QH,
                // S antes que SH) y luego alfabético: que las cestas del mismo
                // tipo salgan juntas, no Q y QH intercaladas por nombre.
                usort($mod['rows'], $this->compareRowsByCodeThenName(...));
            }
            unset($mod);
            $groups[] = [
                'name' => $g['name'],
                'color' => $g['color'],
                'locality' => $g['locality'],
                'subtotal_cestas' => $g['subtotal_cestas'],
                'subtotal_eggs' => $this->formatEggs($g['subtotal_dozens'])['spec'],
                'modalities' => array_values($g['mods']),
            ];
        }

        // Sección "Trasladados" al final (si hay): los que recogen aquí viniendo de otro nodo.
        if ($relocated !== []) {
            $groups[] = $this->buildRelocatedGroup($relocated);
        }

        return $groups;
    }

    /**
     * Sección "Trasladados" del nodo: socios que esta semana recogen aquí viniendo de
     * OTRO nodo (override de nodo). Va al final, con cada fila marcada "de {grupo de
     * casa}" (row['relocated_from']). Mismas subdivisiones por modalidad que un grupo normal.
     *
     * @param DeliveryLine[] $lines
     * @return array{name:string, color:?string, locality:string, subtotal_cestas:float, subtotal_eggs:?string, modalities: list<array{label:string, rows: list<array<string,mixed>>}>}
     */
    private function buildRelocatedGroup(array $lines): array
    {
        $mods = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $bucket = $this->bucketFor($line->basketShareId);
            $mods[$bucket['order']] ??= ['label' => $bucket['label'], 'rows' => []];
            $mods[$bucket['order']]['rows'][] = $this->row($line);
            $subtotal += $line->cestas;
        }
        ksort($mods);
        foreach ($mods as &$mod) {
            usort($mod['rows'], $this->compareRowsByCodeThenName(...));
        }
        unset($mod);

        return [
            'name' => 'Trasladados',
            'color' => null,
            'locality' => '',
            'subtotal_cestas' => $subtotal,
            'subtotal_eggs' => $this->eggSubtotalSpec($lines),
            'modalities' => array_values($mods),
        ];
    }

    /**
     * Sección "Cestas extra" del nodo: entregas "solo extra" de socios SIN suscripción (sin
     * modalidad, basketShareId null). Va al final, una sola lista ordenada por nombre (sin
     * subdividir por modalidad porque no tienen). Ver {@see \App\Entity\PartnerBasketExtra}.
     *
     * @param DeliveryLine[] $lines
     * @return array{name:string, color:?string, locality:string, subtotal_cestas:float, subtotal_eggs:?string, modalities: list<array{label:?string, rows: list<array<string,mixed>>}>}
     */
    private function buildExtraGroup(array $lines): array
    {
        usort($lines, $this->compareByDisplayName(...));

        $rows = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $rows[] = $this->row($line);
            $subtotal += $line->cestas;
        }

        return [
            'name' => 'Cestas extra',
            'color' => null,
            'locality' => '',
            'subtotal_cestas' => $subtotal,
            'subtotal_eggs' => $this->eggSubtotalSpec($lines),
            'modalities' => [['label' => null, 'rows' => $rows]],
        ];
    }

    /**
     * Sección "Albergue" del nodo: cestas de los voluntarios de acogida que
     * recogen aquí esta semana (cesta derivada de su estancia, ver
     * {@see HelperDeliveryResolver}). Va al final, una sola lista ordenada por
     * nombre, sin subdividir por modalidad (no la tienen). Cuenta en los totales
     * del nodo igual que cualquier otra entrega.
     *
     * @param DeliveryLine[] $lines Líneas con isHelper=true.
     * @return array{name:string, color:?string, locality:string, subtotal_cestas:float, subtotal_eggs:?string, modalities: list<array{label:?string, rows: list<array<string,mixed>>}>}
     */
    private function buildHelperGroup(array $lines): array
    {
        usort($lines, $this->compareByDisplayName(...));

        $rows = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $rows[] = $this->row($line);
            $subtotal += $line->cestas;
        }

        return [
            'name' => 'Albergue',
            'color' => null,
            'locality' => '',
            'subtotal_cestas' => $subtotal,
            'subtotal_eggs' => $this->eggSubtotalSpec($lines),
            'modalities' => [['label' => null, 'rows' => $rows]],
        ];
    }

    /**
     * Subtotal de huevos de un conjunto de líneas, en la notación compacta D/M
     * del listado (p. ej. "2D+1M"); null si la sección no lleva huevos. Va en el
     * pie de cada sección, bajo la columna de huevos.
     *
     * @param DeliveryLine[] $lines
     * @return string|null
     */
    private function eggSubtotalSpec(array $lines): ?string
    {
        $dozens = 0.0;
        foreach ($lines as $line) {
            $dozens += $line->dozens;
        }

        return $this->formatEggs($dozens)['spec'];
    }

    /**
     * Bucket de modalidad del listado en papel, tal como lo subdivide el Excel:
     * Semanales (bs 1), Quincenales y mensuales (bs 2 y 3), Solo huevos (bs 5).
     * Las compartidas (4/6/7) no llegan aquí (van a su bloque aparte).
     *
     * @return array{order:int, label:string}
     */
    private function bucketFor(int $basketShareId): array
    {
        return match ($basketShareId) {
            1       => ['order' => 0, 'label' => 'Semanales'],
            2, 3    => ['order' => 1, 'label' => 'Quincenales y mensuales'],
            default => ['order' => 2, 'label' => 'Solo huevos'],
        };
    }

    /**
     * Localidad (pueblo) que se pinta en la celda de color de cada fila. Es el
     * nombre del grupo SIN el sufijo " individuales": el grupo "La Cabrera
     * individuales" reparte en la localidad "La Cabrera"; igual con Tomillares.
     * Los grupos combinados (p.ej. "Alcobendas/Sanse/Tres Cantos") muestran el
     * nombre combinado: el pueblo fino por socio no está en el modelo.
     */
    private function localityFor(string $groupName): string
    {
        return trim(preg_replace('/\s+individuales$/i', '', $groupName));
    }

    /**
     * Bloque de compartidas: una sola lista, alfabética, con cada pareja
     * (Partner.sharePartner) pegada. Cada fila lleva su localidad (nombre del
     * WBG) porque en este bloque no se agrupa por grupo de recogida.
     *
     * @param DeliveryLine[] $lines
     * @return array{rows: list<array<string,mixed>>, subtotal_cestas: float, subtotal_eggs: ?string}
     */
    private function buildShared(array $lines): array
    {
        usort($lines, $this->compareByDisplayName(...));
        [$ordered, $pairEnds] = $this->pinSharedPairs($lines);

        $rows = [];
        $subtotal = 0.0;
        foreach ($ordered as $line) {
            $row = $this->row($line);
            $row['color'] = $line->groupColor;
            $row['pair_end'] = isset($pairEnds[spl_object_id($line)]);
            $rows[] = $row;
            $subtotal += $line->cestas;
        }

        return [
            'rows' => $rows,
            'subtotal_cestas' => $subtotal,
            'subtotal_eggs' => $this->eggSubtotalSpec($lines),
        ];
    }

    /**
     * Fila imprimible de una entrega: nombre de reparto, código de modalidad,
     * cestas físicas y especificación de huevos.
     *
     * @return array{name:string, code:string, cestas:float, egg_spec:?string, egg_count:int, locality:string}
     */
    private function row(DeliveryLine $line): array
    {
        $eggs = $this->formatEggs($line->dozens);

        return [
            'name' => $line->nameForDelivery,
            'code' => $this->deriveCode($line),
            'cestas' => $line->cestas,
            'egg_spec' => $eggs['spec'],
            'egg_count' => $eggs['count'],
            'locality' => $this->localityForRow($line),
            'relocated_from' => $line->relocatedFromLabel,
        ];
    }

    /**
     * Localidad que se pinta en la celda de color de la fila. En los grupos
     * combinados (varios pueblos que recogen en un mismo punto, p.ej.
     * "Alcobendas/Sanse/Tres Cantos") es el pueblo de cada socio (su `city`);
     * en el resto, la localidad del grupo {@see localityFor}. Es la ÚNICA vía
     * por la que `city` entra en el listado, así que la suciedad de `city` en
     * los demás grupos no afecta.
     */
    private function localityForRow(DeliveryLine $line): string
    {
        $groupName = $line->groupName ?? 'Sin grupo';

        if (str_contains($groupName, '/')) {
            $city = $line->city;
            if ($city !== null && trim($city) !== '') {
                return $city;
            }
        }

        return $this->localityFor($groupName);
    }

    /**
     * Código de modalidad del listado (S/Q/M/SC/QC/MC), con sufijo "H" si el
     * hogar está suscrito a huevos. "Solo huevos" (bs.id=5) es "H" a secas.
     */
    private function deriveCode(DeliveryLine $line): string
    {
        // Los voluntarios del albergue no tienen modalidad: celda de código vacía
        // (sin esto, llevar huevos los marcaría "H" = solo-huevos, que es falso —
        // suelen llevar verdura + huevos).
        if ($line->isHelper) {
            return '';
        }

        $bsId = $line->basketShareId;
        $base = self::CODE_BY_BASKET_SHARE[$bsId] ?? '';
        if ($bsId === 5) {
            return $base; // "H": el sufijo de huevos no aplica, ya es solo huevos.
        }

        return $line->subscribedToEggs ? $base . 'H' : $base;
    }

    /**
     * Notación compacta de huevos del listado a partir de las docenas
     * materializadas: 0,5→"1M"/6, 1→"1D"/12, 1,5→"1D+1M"/18, 2→"2D"/24…
     * Devuelve spec=null cuando no hay huevos en la entrega.
     *
     * @return array{spec:?string, count:int}
     */
    private function formatEggs(float $dozens): array
    {
        if ($dozens <= 0.0) {
            return ['spec' => null, 'count' => 0];
        }
        $full = (int) floor($dozens + 1e-9);
        $half = ($dozens - $full) >= 0.5 - 1e-9 ? 1 : 0;

        $parts = [];
        if ($full > 0) {
            $parts[] = $full . 'D';
        }
        if ($half === 1) {
            $parts[] = '1M';
        }

        return [
            'spec' => implode('+', $parts),
            'count' => (int) round($dozens * 12),
        ];
    }

    /**
     * Totales del nodo+día: cestas físicas y docenas de huevos.
     *
     * @param DeliveryLine[] $lines
     * @return array{cestas:float, docenas:float}
     */
    private function computeTotals(array $lines): array
    {
        $cestas = 0.0;
        $docenas = 0.0;
        foreach ($lines as $line) {
            $cestas += $line->cestas;
            $docenas += $line->dozens;
        }

        return ['cestas' => $cestas, 'docenas' => $docenas];
    }

    /**
     * Pega cada pareja de cesta compartida (Partner.sharePartner) consigo,
     * manteniendo el orden alfabético del primero. Devuelve la lista reordenada
     * y el set (por spl_object_id) de líneas que cierran grupo (separador).
     *
     * @param DeliveryLine[] $lines ordenadas alfabéticamente
     * @return array{0: DeliveryLine[], 1: array<int,true>}
     */
    private function pinSharedPairs(array $lines): array
    {
        $byPartnerId = [];
        foreach ($lines as $line) {
            if ($line->partnerId !== null) {
                $byPartnerId[$line->partnerId] = $line;
            }
        }

        $result = [];
        $used = [];
        $pairEnds = [];
        foreach ($lines as $line) {
            $oid = spl_object_id($line);
            if (isset($used[$oid])) {
                continue;
            }
            $result[] = $line;
            $used[$oid] = true;

            $mateId = $line->sharePartnerId;
            $mateLine = $mateId !== null ? ($byPartnerId[$mateId] ?? null) : null;
            if ($mateLine !== null && !isset($used[spl_object_id($mateLine)])) {
                $result[] = $mateLine;
                $used[spl_object_id($mateLine)] = true;
                $pairEnds[spl_object_id($mateLine)] = true;
            } else {
                $pairEnds[$oid] = true;
            }
        }

        return [$result, $pairEnds];
    }

    private function compareByDisplayName(DeliveryLine $a, DeliveryLine $b): int
    {
        return strnatcasecmp($a->nameForDelivery, $b->nameForDelivery);
    }

    /** Orden de los códigos dentro de un grupo: semanales, quincenales, mensuales, solo huevos. */
    private const CODE_ORDER = ['S' => 0, 'SH' => 1, 'Q' => 2, 'QH' => 3, 'M' => 4, 'MH' => 5, 'H' => 6];

    /**
     * Ordena filas por código de modalidad (S, SH, Q, QH, M, MH, H) y, a
     * igualdad de código, alfabéticamente por nombre de reparto. El orden es el
     * explícito de {@see CODE_ORDER}, no alfabético, para que quincenales vayan
     * antes que mensuales (y no "MH" antes que "Q" por orden de letras).
     *
     * @param array{code:string, name:string} $a
     * @param array{code:string, name:string} $b
     */
    private function compareRowsByCodeThenName(array $a, array $b): int
    {
        $oa = self::CODE_ORDER[$a['code']] ?? 99;
        $ob = self::CODE_ORDER[$b['code']] ?? 99;

        return $oa <=> $ob ?: strnatcasecmp($a['name'], $b['name']);
    }
}
