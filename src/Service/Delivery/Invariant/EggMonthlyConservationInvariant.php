<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\DeliveryException;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L17 — Conservación mensual de docenas: la suma de docenas materializadas de
 * un socio en un mes debe ser lo CONTRATADO (egg_amount × frecuencia del
 * egg_period), más las extras puntuales. Es un AGREGADO calculado desde la
 * semántica del catálogo (semanal = todas las semanas operativas de su nodo,
 * quincenal = la mitad, mensual = 1), NO desde EggDeliveryResolver — por eso
 * caza bugs DENTRO del resolver sin ser juez y parte (habría pillado MIRIAM,
 * huevos todos los viernes, y los huevos-cero de Madrid).
 *
 * Solo juzga meses COMPLETOS y completamente generados (cada semana con WBs
 * o cierre global) — con materialización tardía eso casi solo ocurre en el
 * clon de la batería, que es su hábitat. Se excluyen sin juzgar: meses
 * tocados por un traslado de festivo que cruza de mes (frontera, L27),
 * socios cuyo PBS no cubre el mes entero o cambia a mitad, y socios con un
 * shift que cruza de mes. Los saltos (no-recoge) no distorsionan: el WB
 * saltado conserva sus ítems.
 */
final class EggMonthlyConservationInvariant extends AbstractInvariant
{
    private const EGG_PERIOD_WEEKLY = 1;
    private const EGG_PERIOD_BIWEEKLY = 2;
    private const EGG_PERIOD_MONTHLY = 3;
    private const EPSILON = 0.01;

    public function __construct(
        EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L17';
    }

    public function name(): string
    {
        return 'Conservación mensual de docenas (agregado por socio)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var Basket[] $baskets */
        $baskets = $this->em->createQuery(
            'SELECT b FROM ' . Basket::class . ' b WHERE b.date >= :from ORDER BY b.date ASC'
        )->setParameter('from', $from)->getResult();

        // Meses candidatos: solo COMPLETOS dentro de la ventana.
        $byMonth = [];
        foreach ($baskets as $basket) {
            $byMonth[$basket->getDate()->format('Y-m')][] = $basket;
        }
        if ($from->format('j') !== '1') {
            unset($byMonth[$from->format('Y-m')]);
        }
        if ($byMonth === []) {
            return [];
        }

        $generated = $this->generatedBasketIds($from);
        [$globallyClosed, $borderMonths] = $this->exceptionMaps($from);

        // Meses juzgables: cada semana generada o cerrada en global, y sin frontera.
        $months = array_filter(
            $byMonth,
            function (array $monthBaskets, string $ym) use ($generated, $globallyClosed, $borderMonths): bool {
                if (isset($borderMonths[$ym])) {
                    return false;
                }
                foreach ($monthBaskets as $basket) {
                    if (!isset($generated[$basket->getId()]) && !isset($globallyClosed[$basket->getId()])) {
                        return false;
                    }
                }

                return true;
            },
            ARRAY_FILTER_USE_BOTH
        );
        if ($months === []) {
            return [];
        }

        /** @var PartnerBasketShare[] $eggShares */
        $eggShares = $this->em->createQuery(
            'SELECT pbs, p, ea, g, n FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             JOIN pbs.egg_amount ea
             JOIN pbs.egg_period ep
             JOIN p.weekly_basket_group g
             JOIN g.node n
             WHERE pbs.is_active = 1 AND p.is_active = 1 AND p.parent IS NULL'
        )->getResult();
        if ($eggShares === []) {
            return [];
        }

        $actualByPartnerMonth = $this->eggSumsByPartnerMonth($from);
        $extraByPartnerMonth = $this->extraEggsByPartnerMonth($from);
        $crossMonthShiftPartners = $this->crossMonthShiftPartners($from);
        $overlappingPbsCount = $this->pbsOverlapCounts(
            array_unique(array_map(
                static fn (PartnerBasketShare $pbs): int => $pbs->getPartner()->getId(),
                $eggShares
            )),
            array_keys($months)
        );

        $violations = [];
        $opsCache = [];
        foreach ($months as $ym => $monthBaskets) {
            $monthStart = $monthBaskets[0]->getDate()->format('Y-m-01');
            $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

            foreach ($eggShares as $pbs) {
                $partner = $pbs->getPartner();
                $pid = $partner->getId();

                // El PBS debe cubrir el mes ENTERO y ser el único que lo toca.
                $start = $pbs->getStartDate()?->format('Y-m-d');
                $end = $pbs->getEndDate()?->format('Y-m-d');
                if (($start !== null && $start > $monthStart) || ($end !== null && $end < $monthEnd)) {
                    continue;
                }
                if (($overlappingPbsCount[$pid][$ym] ?? 1) > 1 || isset($crossMonthShiftPartners[$pid][$ym])) {
                    continue;
                }

                $node = $partner->getWeeklyBasketGroup()->getNode();
                if ($node->getDeliveryWeekday() === null) {
                    continue; // Nodo legacy: cadencia injuzgable.
                }
                $opsKey = $node->getId() . '|' . $ym;
                if (!isset($opsCache[$opsKey])) {
                    $ops = 0;
                    foreach ($monthBaskets as $basket) {
                        if ($this->nodeDeliveryDate->physicalDateFor($basket, $node) !== null) {
                            $ops++;
                        }
                    }
                    $opsCache[$opsKey] = $ops;
                }
                $ops = $opsCache[$opsKey];

                $slotOptions = match ($pbs->getEggPeriod()?->getId()) {
                    self::EGG_PERIOD_WEEKLY => [$ops],
                    self::EGG_PERIOD_BIWEEKLY => $ops > 0 ? array_unique([intdiv($ops, 2), (int) ceil($ops / 2)]) : [0],
                    self::EGG_PERIOD_MONTHLY => [$ops > 0 ? 1 : 0],
                    default => null,
                };
                if ($slotOptions === null) {
                    continue;
                }

                $dozens = $pbs->getEggAmount()->getDozens();
                $extra = $extraByPartnerMonth[$pid][$ym] ?? 0.0;
                $actual = $actualByPartnerMonth[$pid][$ym] ?? 0.0;

                $matches = false;
                foreach ($slotOptions as $slots) {
                    if (abs($actual - ($dozens * $slots + $extra)) < self::EPSILON) {
                        $matches = true;
                        break;
                    }
                }
                if (!$matches) {
                    $expectedLabel = implode(' o ', array_map(
                        static fn (int $slots): string => (string) ($dozens * $slots + $extra),
                        $slotOptions
                    ));
                    $violations[] = sprintf(
                        '%s (%d): %s suma %s docenas, esperadas %s (%s × %s entregas%s).',
                        $partner->getName(),
                        $pid,
                        $ym,
                        rtrim(rtrim(number_format($actual, 2, '.', ''), '0'), '.'),
                        $expectedLabel,
                        $dozens,
                        implode('/', $slotOptions),
                        $extra > 0 ? sprintf(' + %s extra', $extra) : ''
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Ids de Basket de la ventana que tienen al menos un WB (semana generada).
     *
     * @return array<int, true>
     */
    private function generatedBasketIds(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT b.id AS bid FROM ' . WeeklyBasket::class . ' wb JOIN wb.basket b WHERE b.date >= :from'
        )->setParameter('from', $from)->getArrayResult();

        return array_fill_keys(array_column($rows, 'bid'), true);
    }

    /**
     * Mapas de excepciones de la ventana: baskets con cierre GLOBAL, y meses
     * frontera (un traslado cuya fecha cae en OTRO mes contamina ambos meses).
     *
     * @return array{0: array<int, true>, 1: array<string, true>}
     */
    private function exceptionMaps(\DateTimeImmutable $from): array
    {
        /** @var DeliveryException[] $exceptions */
        $exceptions = $this->em->createQuery(
            'SELECT e, b FROM ' . DeliveryException::class . ' e JOIN e.basket b WHERE b.date >= :from'
        )->setParameter('from', $from)->getResult();

        $globallyClosed = [];
        $borderMonths = [];
        foreach ($exceptions as $e) {
            if ($e->isCancelled() && $e->isGlobal()) {
                $globallyClosed[$e->getBasket()->getId()] = true;
            }
            $shifted = $e->getShiftedDate();
            if ($shifted !== null && $shifted->format('Y-m') !== $e->getBasket()->getDate()->format('Y-m')) {
                $borderMonths[$e->getBasket()->getDate()->format('Y-m')] = true;
                $borderMonths[$shifted->format('Y-m')] = true;
            }
        }

        return [$globallyClosed, $borderMonths];
    }

    /**
     * Suma de docenas materializadas (ítems de componente huevo, saltados
     * incluidos) por socio y mes.
     *
     * @return array<int, array<string, float>>
     */
    private function eggSumsByPartnerMonth(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(wb.partner) AS pid, b.date AS bdate, i.amount AS amount
             FROM ' . WeeklyBasketItem::class . ' i
             JOIN i.weeklyBasket wb
             JOIN wb.basket b
             WHERE b.date >= :from AND IDENTITY(i.basketComponent) = :eggs'
        )->setParameter('from', $from)
         ->setParameter('eggs', BasketComponent::ID_EGGS)
         ->getArrayResult();

        $sums = [];
        foreach ($rows as $r) {
            $ym = $r['bdate']->format('Y-m');
            $sums[$r['pid']][$ym] = ($sums[$r['pid']][$ym] ?? 0.0) + (float) $r['amount'];
        }

        return $sums;
    }

    /**
     * Delta de docenas extra (PartnerBasketExtra de componente huevo) por
     * socio y mes — se SUMA a lo esperado, no excluye al socio.
     *
     * @return array<int, array<string, float>>
     */
    private function extraEggsByPartnerMonth(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(x.partner) AS pid, b.date AS bdate, x.amount AS amount
             FROM ' . PartnerBasketExtra::class . ' x
             JOIN x.basket b
             WHERE b.date >= :from AND IDENTITY(x.component) = :eggs'
        )->setParameter('from', $from)
         ->setParameter('eggs', BasketComponent::ID_EGGS)
         ->getArrayResult();

        $sums = [];
        foreach ($rows as $r) {
            $ym = $r['bdate']->format('Y-m');
            $sums[$r['pid']][$ym] = ($sums[$r['pid']][$ym] ?? 0.0) + (float) $r['amount'];
        }

        return $sums;
    }

    /**
     * Socios con un shift de entrega entera que CRUZA de mes (origen y destino
     * en meses distintos): sus dos meses no se juzgan, la cesta viajó de uno a otro.
     *
     * @return array<int, array<string, true>>
     */
    private function crossMonthShiftPartners(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(s.partner) AS pid, fb.date AS fdate, tb.date AS tdate
             FROM ' . PartnerDeliveryShift::class . ' s
             JOIN s.fromBasket fb
             JOIN s.toBasket tb
             WHERE s.component IS NULL AND (fb.date >= :from OR tb.date >= :from)'
        )->setParameter('from', $from)->getArrayResult();

        $skip = [];
        foreach ($rows as $r) {
            $fromYm = $r['fdate']->format('Y-m');
            $toYm = $r['tdate']->format('Y-m');
            if ($fromYm !== $toYm) {
                $skip[$r['pid']][$fromYm] = true;
                $skip[$r['pid']][$toYm] = true;
            }
        }

        return $skip;
    }

    /**
     * Cuántos PBS (activos o no) de cada socio tocan cada mes candidato — más
     * de uno significa cambio de configuración a mitad de mes y ese mes no se
     * juzga para ese socio. Un PBS sin fechas cubre cualquier mes.
     *
     * @param int[]    $partnerIds
     * @param string[] $months Meses candidatos en formato Y-m.
     * @return array<int, array<string, int>>
     */
    private function pbsOverlapCounts(array $partnerIds, array $months): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(pbs.partner) AS pid, pbs.start_date AS sdate, pbs.end_date AS edate
             FROM ' . PartnerBasketShare::class . ' pbs
             WHERE pbs.partner IN (:pids)'
        )->setParameter('pids', $partnerIds)->getArrayResult();

        $counts = [];
        foreach ($rows as $r) {
            $start = $r['sdate']?->format('Y-m-d');
            $end = $r['edate']?->format('Y-m-d');
            foreach ($months as $ym) {
                $monthStart = $ym . '-01';
                $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
                if (($start === null || $start <= $monthEnd) && ($end === null || $end >= $monthStart)) {
                    $counts[$r['pid']][$ym] = ($counts[$r['pid']][$ym] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }
}
