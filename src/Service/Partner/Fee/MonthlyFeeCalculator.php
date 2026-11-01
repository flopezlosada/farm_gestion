<?php

namespace App\Service\Partner\Fee;

use App\Entity\Basket;
use App\Entity\FeeAdjustment;
use App\Entity\FeeConcept;
use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Entity\PartnerPause;
use App\Repository\FeeAdjustmentRepository;
use App\Repository\FeeRateRepository;
use App\Repository\PartnerPauseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La cuota de cada socix en un mes: la tarifa de sus cestas, más sus ajustes.
 *
 * Cada cesta paga por las SEMANAS DE REPARTO del mes en que la tuvo, contadas sobre
 * el ciclo semanal global (un {@see Basket} por viernes): quien pasa de semanal a
 * quincenal el día 15 paga la parte de semanal por las semanas que lo fue y el resto
 * como quincenal. En pausa o de baja no se cobra: las pausas salen de sus periodos
 * ({@see PartnerPause}, los mismos que lee el reparto, también los programados) y las
 * bajas de los eventos de baja y vuelta de su ficha. Si una tarifa cambia a mitad de
 * mes, cada semana usa la que valía ese día.
 *
 * El transporte lo pone el grupo de recogida de quien recoge la cesta (no el de quien
 * la paga), con el precio que tenga cada semana: {@see FeeSchedule::transportMonthPrice}.
 * Va por cada cesta: dos cestas, dos transportes.
 *
 * Las semanas cerradas por un festivo o los cambios puntuales de una semana NO
 * descuentan: la cuota es mensual y se paga por la cesta, no por cada reparto.
 *
 * Todo en unas pocas consultas, sea cual sea el número de socixs: semanas del mes,
 * cestas, tarifas, ajustes, pausas y eventos.
 *
 * No es final para poder sustituirlo en las pruebas de quien sólo usa sus totales.
 */
class MonthlyFeeCalculator
{
    /**
     * Lo que deja de cobrarse desde que pasa y vuelve a cobrarse desde que pasa. Las
     * pausas NO van aquí: tienen sus periodos propios, con fechas que pueden estar por
     * llegar, y sus eventos sólo se registran cuando el estado cambia.
     */
    private const STOPS = [PartnerEvent::TYPE_LEAVE];
    private const RESTARTS = [PartnerEvent::TYPE_JOIN];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeeRateRepository $rates,
        private readonly FeeAdjustmentRepository $adjustments,
        private readonly PartnerPauseRepository $pauses,
    ) {
    }

    /** Las cuotas de un mes (cualquier día de él), con lo que hay en la base. */
    public function forMonth(\DateTimeInterface $month): MonthlyFees
    {
        $month = FeeAdjustment::month($month);
        $end = $month->modify('last day of this month');

        return self::compute(
            $month,
            $this->weeks($month, $end),
            $this->shares($month, $end),
            $this->rates->schedule(),
            $this->adjustments->findApplyingTo($month),
            $this->stopEvents($end),
            $this->pauses->findTouching($month, $end),
        );
    }

    /**
     * Lo que suman las cestas de todo el mundo en un mes, por concepto: verdura,
     * huevos y transporte, sin ajustes.
     */
    public function basketTotalsFor(\DateTimeInterface $month): FeeTotals
    {
        return $this->forMonth($month)->getBasketTotals();
    }

    /**
     * El cálculo, sin tocar la base: con las semanas del mes, las cestas que pudieron
     * estar en marcha, las tarifas, los ajustes del mes, los eventos de baja y las pausas.
     *
     * @param list<\DateTimeImmutable>  $weeks
     * @param list<PartnerBasketShare>  $shares
     * @param list<FeeAdjustment>       $adjustments
     * @param list<PartnerEvent>        $events      Bajas y vueltas, en orden.
     * @param list<PartnerPause>        $pauses      Pausas que tocan el mes.
     */
    public static function compute(\DateTimeImmutable $month, array $weeks, array $shares, FeeSchedule $schedule, array $adjustments, array $events, array $pauses = []): MonthlyFees
    {
        $stops = self::stopPeriods($events);
        foreach ($pauses as $pause) {
            $stops[MonthlyFees::keyOf($pause->getPartner())][] = [$pause->getStartsOn(), $pause->getResumesOn()];
        }
        $statements = [];
        $statementFor = static function (Partner $payer) use (&$statements): FeeStatement {
            return $statements[MonthlyFees::keyOf($payer)] ??= new FeeStatement($payer);
        };

        foreach ($shares as $share) {
            $partner = $share->getPartner();
            $line = self::line($share, $weeks, $schedule, $stops[MonthlyFees::keyOf($partner)] ?? []);
            if ($line !== null) {
                $statementFor($share->getEffectivePayer()->getFamilyHead())->addLine($line);
            }
        }
        foreach ($adjustments as $adjustment) {
            if ($adjustment->appliesTo($month)) {
                $statementFor($adjustment->getPartner()->getFamilyHead())->addAdjustment($adjustment);
            }
        }

        return new MonthlyFees($month, $weeks, $statements);
    }

    /**
     * Lo que cuesta una cesta en el mes, o null si no la tuvo ninguna semana.
     *
     * @param list<\DateTimeImmutable>                          $weeks
     * @param list<array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}> $stops Periodos sin cobrar [desde, hasta).
     */
    private static function line(PartnerBasketShare $share, array $weeks, FeeSchedule $schedule, array $stops): ?FeeLine
    {
        $active = array_values(array_filter($weeks, static fn (\DateTimeImmutable $w): bool => self::holds($share, $w) && !self::stopped($stops, $w)));
        if ($active === [] || $weeks === []) {
            return null;
        }

        $missing = [];
        $veg = $egg = $transport = 0;
        $basketShare = $share->getBasketShare();
        $group = $share->getPartner()?->getWeeklyBasketGroup();
        $eggAmount = $share->getEggAmount();
        $eggPeriod = $share->getEggPeriod();
        foreach ($active as $week) {
            $vegPrice = $basketShare !== null ? $schedule->vegMonthPrice($basketShare, $week) : '0.00';
            if ($vegPrice === null) {
                $missing[] = FeeConcept::forBasketShare($basketShare);
            } else {
                $veg += IssuedInvoice::toCents($vegPrice) * (int) $share->getAmount();
            }
            $transport += IssuedInvoice::toCents($schedule->transportMonthPrice($group, $basketShare, $week)) * (int) $share->getAmount();
            if ($eggAmount !== null && $eggPeriod !== null) {
                $dozenPrice = $schedule->eggDozenMonthPrice($eggPeriod, $week);
                if ($dozenPrice === null) {
                    $missing[] = FeeConcept::forEggPeriod($eggPeriod);
                } else {
                    $egg += IssuedInvoice::toCents($dozenPrice) * $eggAmount->getDozens();
                }
            }
        }

        // Cada semana es una parte igual del mes: la suma de las cuotas mensuales de
        // las semanas que la tuvo, entre las semanas del mes. Se redondea una vez.
        $n = \count($weeks);

        return new FeeLine(
            $share,
            (int) round($veg / $n),
            (int) round($egg / $n),
            (int) round($transport / $n),
            \count($active),
            $n,
            array_values(array_unique(array_filter($missing), \SORT_REGULAR)),
        );
    }

    /**
     * Si una cesta estaba en marcha una semana: ya había empezado (o no tiene fecha de
     * inicio, cosa de las importadas) y no había terminado. Sin fecha de fin vale
     * mientras esté activa: una cerrada sin fecha no se cobra para siempre.
     */
    private static function holds(PartnerBasketShare $share, \DateTimeImmutable $week): bool
    {
        $start = $share->getStartDate();
        $end = $share->getEndDate();

        return ($start === null || $start->format('Y-m-d') <= $week->format('Y-m-d'))
            && ($end !== null ? $end->format('Y-m-d') >= $week->format('Y-m-d') : (bool) $share->getIsActive());
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}> $stops
     */
    private static function stopped(array $stops, \DateTimeImmutable $week): bool
    {
        foreach ($stops as [$from, $until]) {
            if ($from <= $week && ($until === null || $week < $until)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las semanas de reparto del mes: la fecha de cada ciclo semanal global.
     *
     * @return list<\DateTimeImmutable>
     */
    private function weeks(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $dates = $this->em->createQueryBuilder()
            ->select('b.date')->from(Basket::class, 'b')
            ->andWhere('b.date BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()->getSingleColumnResult();

        // Una columna suelta llega como texto («2026-08-07»), sin convertir a fecha.
        return array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable(substr($d, 0, 10)), $dates);
    }

    /**
     * Las cestas que pudieron estar en marcha algún día del mes, con todo lo que hace
     * falta para cobrarlas, en una consulta.
     *
     * @return list<PartnerBasketShare>
     */
    private function shares(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->em->createQueryBuilder()
            ->select('s', 'p', 'g', 'head', 'payer', 'payerHead', 'bs', 'ea', 'ep')
            ->from(PartnerBasketShare::class, 's')
            ->join('s.partner', 'p')
            ->leftJoin('p.weekly_basket_group', 'g')
            ->leftJoin('p.parent', 'head')
            ->leftJoin('s.payer_partner', 'payer')
            ->leftJoin('payer.parent', 'payerHead')
            ->leftJoin('s.basket_share', 'bs')
            ->leftJoin('s.egg_amount', 'ea')
            ->leftJoin('s.egg_period', 'ep')
            ->andWhere('s.start_date IS NULL OR s.start_date <= :end')
            ->andWhere('s.end_date >= :start OR (s.end_date IS NULL AND s.is_active = true)')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->getQuery()->getResult();
    }

    /**
     * Los eventos de baja y vuelta hasta el final del mes, en orden.
     *
     * @return list<PartnerEvent>
     */
    private function stopEvents(\DateTimeImmutable $until): array
    {
        return $this->em->createQueryBuilder()
            ->select('e', 'p')->from(PartnerEvent::class, 'e')
            ->join('e.partner', 'p')
            ->andWhere('e.type IN (:types)')->setParameter('types', [...self::STOPS, ...self::RESTARTS])
            ->andWhere('e.occurredAt <= :until')->setParameter('until', $until->setTime(23, 59, 59))
            ->orderBy('e.occurredAt', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Los periodos en que cada socix estuvo de baja, de sus eventos: desde una baja
     * hasta la vuelta siguiente, [desde, hasta).
     *
     * @param list<PartnerEvent> $events En orden.
     *
     * @return array<int, list<array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}>> Por socix.
     */
    public static function stopPeriods(array $events): array
    {
        $periods = [];
        $open = [];
        foreach ($events as $event) {
            $id = MonthlyFees::keyOf($event->getPartner());
            $day = \DateTimeImmutable::createFromInterface($event->getOccurredAt())->setTime(0, 0);
            if (\in_array($event->getType(), self::STOPS, true)) {
                $open[$id] ??= $day;
            } elseif (isset($open[$id])) {
                $periods[$id][] = [$open[$id], $day];
                unset($open[$id]);
            }
        }
        foreach ($open as $id => $from) {
            $periods[$id][] = [$from, null];
        }

        return $periods;
    }
}
