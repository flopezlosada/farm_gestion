<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Entity\PartnerBasketShare;
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
 * Es un shaper puro: lee de los repositorios y no muta nada. NO dispara la
 * generación de WeeklyBasket (eso es del cron / de la pantalla v2); si el día
 * no está materializado, la hoja sale vacía.
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
    ) {
    }

    /**
     * Estructura imprimible del nodo en el día dado.
     *
     * @return array{
     *   groups: list<array{name:string, color:?string, locality:string, subtotal_cestas:float, modalities: list<array{label:string, rows: list<array<string,mixed>>}>}>,
     *   shared: array{rows: list<array<string,mixed>>},
     *   totals: array{cestas:float, docenas:float}
     * }
     */
    public function build(Node $node, Basket $basket): array
    {
        $weeklyBaskets = $this->weeklyBasketRepo->findForNodeAndBasket($node, $basket);

        // Cestas físicas (verdura) y docenas (huevos) ya materializadas por
        // entrega: la ponderación ½ de las compartidas y el 0 de "solo huevos"
        // están estampados en el ítem, así que aquí solo se leen y suman.
        $componentAmounts = $this->weeklyBasketItemRepo->componentAmountsFor($weeklyBaskets);

        // PartnerBasketShare activo por entrega: hace falta para el código de
        // modalidad (saber si el hogar está suscrito a huevos → sufijo "H").
        $pbsByWbId = [];
        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            $pbsByWbId[$wb->getId()] = $partner !== null
                ? $this->partnerBasketShareRepo->findActiveForPartner($partner, $basket->getDate())
                : null;
        }

        // Partición: compartidas (bloque aparte) vs resto (por grupo).
        $shared = [];
        $regular = [];
        foreach ($weeklyBaskets as $wb) {
            $bs = $wb->getBasketShare();
            if ($bs === null) {
                continue;
            }
            if (in_array($bs->getId(), self::SHARED_BASKET_SHARE_IDS, true)) {
                $shared[] = $wb;
            } else {
                $regular[] = $wb;
            }
        }

        return [
            'groups' => $this->buildGroups($regular, $componentAmounts, $pbsByWbId),
            'shared' => $this->buildShared($shared, $componentAmounts, $pbsByWbId),
            'totals' => $this->computeTotals($weeklyBaskets, $componentAmounts),
        ];
    }

    /**
     * Grupos de recogida (WBG) en orden alfabético; dentro, subgrupos por
     * modalidad (bs.id ascendente: S, Q, M, H) con su subtotal de cestas.
     *
     * @param WeeklyBasket[]                 $weeklyBaskets
     * @param array<int,array<int,float>>    $componentAmounts
     * @param array<int,?PartnerBasketShare> $pbsByWbId
     * @return list<array{name:string, color:?string, subtotal_cestas:float, modalities: list<array{label:string, rows: list<array<string,mixed>>}>}>
     */
    private function buildGroups(array $weeklyBaskets, array $componentAmounts, array $pbsByWbId): array
    {
        $byWbg = [];
        foreach ($weeklyBaskets as $wb) {
            $wbg = $wb->getWeeklyBasketGroup();
            $bs = $wb->getBasketShare();
            if ($wbg === null || $bs === null) {
                continue;
            }
            $gid = $wbg->getId();
            $bucket = $this->bucketFor($bs->getId());
            $byWbg[$gid] ??= [
                'name' => $wbg->getName(),
                'color' => $wbg->getColor(),
                'locality' => $this->localityFor($wbg->getName()),
                'mods' => [],
                'subtotal_cestas' => 0.0,
            ];
            $byWbg[$gid]['mods'][$bucket['order']] ??= ['label' => $bucket['label'], 'rows' => []];
            $byWbg[$gid]['mods'][$bucket['order']]['rows'][] = $this->row($wb, $componentAmounts, $pbsByWbId);
            $byWbg[$gid]['subtotal_cestas'] += $this->cestasOf($wb, $componentAmounts);
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
                'modalities' => array_values($g['mods']),
            ];
        }

        return $groups;
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
     * @param WeeklyBasket[]                 $weeklyBaskets
     * @param array<int,array<int,float>>    $componentAmounts
     * @param array<int,?PartnerBasketShare> $pbsByWbId
     * @return array{rows: list<array<string,mixed>>}
     */
    private function buildShared(array $weeklyBaskets, array $componentAmounts, array $pbsByWbId): array
    {
        usort($weeklyBaskets, $this->compareByDisplayName(...));
        [$ordered, $pairEnds] = $this->pinSharedPairs($weeklyBaskets);

        $rows = [];
        foreach ($ordered as $wb) {
            $row = $this->row($wb, $componentAmounts, $pbsByWbId);
            $row['color'] = $wb->getWeeklyBasketGroup()?->getColor();
            $row['pair_end'] = isset($pairEnds[$wb->getId()]);
            $rows[] = $row;
        }

        return ['rows' => $rows];
    }

    /**
     * Fila imprimible de una entrega: nombre de reparto, código de modalidad,
     * cestas físicas y especificación de huevos.
     *
     * @param array<int,array<int,float>>    $componentAmounts
     * @param array<int,?PartnerBasketShare> $pbsByWbId
     * @return array{name:string, code:string, cestas:float, egg_spec:?string, egg_count:int, locality:string}
     */
    private function row(WeeklyBasket $wb, array $componentAmounts, array $pbsByWbId): array
    {
        $dozens = $componentAmounts[$wb->getId()][BasketComponent::ID_EGGS] ?? 0.0;
        $eggs = $this->formatEggs($dozens);

        return [
            'name' => $wb->getPartner()?->getNameForDelivery() ?? '',
            'code' => $this->deriveCode($wb, $pbsByWbId[$wb->getId()] ?? null),
            'cestas' => $this->cestasOf($wb, $componentAmounts),
            'egg_spec' => $eggs['spec'],
            'egg_count' => $eggs['count'],
            'locality' => $this->localityForRow($wb),
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
    private function localityForRow(WeeklyBasket $wb): string
    {
        $wbg = $wb->getWeeklyBasketGroup();
        $groupName = $wbg?->getName() ?? 'Sin grupo';

        if (str_contains($groupName, '/')) {
            $city = $wb->getPartner()?->getCity()?->getName();
            if ($city !== null && trim($city) !== '') {
                return $city;
            }
        }

        return $this->localityFor($groupName);
    }

    /**
     * @param array<int,array<int,float>> $componentAmounts
     */
    private function cestasOf(WeeklyBasket $wb, array $componentAmounts): float
    {
        return $componentAmounts[$wb->getId()][BasketComponent::ID_VEGETABLES] ?? 0.0;
    }

    /**
     * Código de modalidad del listado (S/Q/M/SC/QC/MC), con sufijo "H" si el
     * hogar está suscrito a huevos. "Solo huevos" (bs.id=5) es "H" a secas.
     */
    private function deriveCode(WeeklyBasket $wb, ?PartnerBasketShare $pbs): string
    {
        $bsId = $wb->getBasketShare()?->getId();
        $base = self::CODE_BY_BASKET_SHARE[$bsId] ?? '';
        if ($bsId === 5) {
            return $base; // "H": el sufijo de huevos no aplica, ya es solo huevos.
        }
        $subscribedToEggs = $pbs?->getEggAmount() !== null;

        return $subscribedToEggs ? $base . 'H' : $base;
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
     * @param WeeklyBasket[]              $weeklyBaskets
     * @param array<int,array<int,float>> $componentAmounts
     * @return array{cestas:float, docenas:float}
     */
    private function computeTotals(array $weeklyBaskets, array $componentAmounts): array
    {
        $cestas = 0.0;
        $docenas = 0.0;
        foreach ($weeklyBaskets as $wb) {
            $cestas += $componentAmounts[$wb->getId()][BasketComponent::ID_VEGETABLES] ?? 0.0;
            $docenas += $componentAmounts[$wb->getId()][BasketComponent::ID_EGGS] ?? 0.0;
        }

        return ['cestas' => $cestas, 'docenas' => $docenas];
    }

    /**
     * Pega cada pareja de cesta compartida (Partner.sharePartner) consigo,
     * manteniendo el orden alfabético del primero. Devuelve la lista reordenada
     * y el set de wb.id que cierran grupo (para pintar separador).
     *
     * @param WeeklyBasket[] $rows ordenados alfabéticamente
     * @return array{0: WeeklyBasket[], 1: array<int,true>}
     */
    private function pinSharedPairs(array $rows): array
    {
        $byPartnerId = [];
        foreach ($rows as $wb) {
            $partner = $wb->getPartner();
            if ($partner !== null) {
                $byPartnerId[$partner->getId()] = $wb;
            }
        }

        $result = [];
        $used = [];
        $pairEnds = [];
        foreach ($rows as $wb) {
            $wbId = $wb->getId();
            if (isset($used[$wbId])) {
                continue;
            }
            $result[] = $wb;
            $used[$wbId] = true;

            $mate = $wb->getPartner()?->getSharePartner();
            $mateWb = $mate !== null ? ($byPartnerId[$mate->getId()] ?? null) : null;
            if ($mateWb !== null && !isset($used[$mateWb->getId()])) {
                $result[] = $mateWb;
                $used[$mateWb->getId()] = true;
                $pairEnds[$mateWb->getId()] = true;
            } else {
                $pairEnds[$wbId] = true;
            }
        }

        return [$result, $pairEnds];
    }

    private function compareByDisplayName(WeeklyBasket $a, WeeklyBasket $b): int
    {
        return strnatcasecmp(
            $a->getPartner()?->getNameForDelivery() ?? '',
            $b->getPartner()?->getNameForDelivery() ?? '',
        );
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
