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
use App\Entity\Worker;
use App\Entity\WorkerBasketWeek;
use App\Repository\FeeAdjustmentRepository;
use App\Repository\FeeRateRepository;
use App\Repository\PartnerPauseRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La cuota de cada socix en un mes: la tarifa de sus cestas, más sus ajustes.
 *
 * Se paga la cuota que corresponde a la cesta, la recoja o no. Cada cesta paga por las
 * SEMANAS DE REPARTO del mes en que la tuvo, contadas sobre el ciclo semanal global (un
 * {@see Basket} por viernes). En pausa o de baja no se cobra: las pausas salen de sus
 * periodos ({@see PartnerPause}, los mismos que lee el reparto, también los
 * programados) y las bajas de los eventos de baja y vuelta de su ficha. Si una tarifa
 * cambia a mitad de mes, cada semana usa la que valía ese día.
 *
 * Una cesta que empieza después del primer reparto del mes es un CAMBIO si la ficha ya
 * tenía cesta en ese primer reparto, y un ALTA A MITAD si no:
 * - Cambio (de modalidad, de turno o de fecha): ese mes se paga entera la cesta que tenía
 *   al empezarlo, aunque termine antes, y la nueva no paga nada hasta el mes siguiente.
 *   Un aumento a mitad de mes se cobra aparte, con un ajuste.
 * - Alta a mitad: una semanal o una quincenal paga medio mes ({@see FeeLine::$halfMonth}),
 *   aunque en un mes de cinco viernes reciba una cesta de más. Una quincenal cuya primera
 *   cesta es la del segundo viernes no ha perdido ninguna de su turno: paga el mes
 *   entero. Una mensual paga entera si su cesta cae ese mes, y nada si no.
 * Qué cestas recibe una quincenal o una mensual lo dice el patrón del reparto
 * ({@see WeeklyBasketGenerator::projectForBasket}: modalidad, turno, orden mensual y
 * nodo), sin los cambios puntuales, que no cambian la cuota. Las cestas ya cerradas no
 * salen en ese patrón: para ellas cuenta la primera semana en que la tuvo.
 *
 * El transporte lo pone el grupo de recogida de quien recoge la cesta (no el de quien
 * la paga), con el precio que tenga cada semana: {@see FeeSchedule::transportMonthPrice}.
 * Va por cada cesta: dos cestas, dos transportes. El grupo es el que tiene HOY: la
 * ficha no guarda en qué grupo estuvo antes, así que recalcular un mes pasado de quien
 * ha cambiado de grupo da el transporte del grupo nuevo. Lo cobrado de verdad queda en
 * cada remesa enviada.
 *
 * Además, cada hogar paga sus cuotas de socix ({@see FeeCharge}): la de inscripción el
 * mes en que empieza su primera cesta (la más antigua de las que tiene su familia: quien
 * vuelve y conserva una cesta antigua no la paga otra vez) y la de mantenimiento en
 * junio y diciembre, si ese mes tiene alguna cesta que cobrar. Van en el recibo de quien
 * paga la primera cesta del hogar (por id, para que no dependa del orden de la base).
 * Las cestas importadas sin fecha de inicio son de hogares de antes: nunca pagan
 * inscripción. Si la primera cesta empieza después del último reparto de su mes, la
 * inscripción va con el primer reparto, al mes siguiente. Límite conocido: si el mes
 * del primer reparto está entero en pausa, la inscripción no se cobra nunca; no se
 * guarda quién la pagó.
 *
 * La cesta de quien trabaja en la asociación no se cobra las semanas que trabajaba, ni
 * tras su baja si se decidió regalársela ({@see Worker::basketWeek()}). Si todas las
 * cestas de su hogar ese mes son gratis así, tampoco paga inscripción ni mantenimiento.
 *
 * Las semanas cerradas por un festivo o los cambios puntuales de una semana NO
 * descuentan: la cuota es mensual y se paga por la cesta, no por cada reparto.
 *
 * Todo en unas pocas consultas, sea cual sea el número de socixs: semanas del mes,
 * cestas, tarifas, ajustes, pausas, eventos y trabajadorxs. El patrón del reparto se
 * pide una vez por semana del mes, y sólo si alguna cesta empieza a medias.
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
        private readonly WeeklyBasketGenerator $generator,
    ) {
    }

    /** Las cuotas de un mes (cualquier día de él), con lo que hay en la base. */
    public function forMonth(\DateTimeInterface $month): MonthlyFees
    {
        $month = FeeAdjustment::month($month);
        $end = $month->modify('last day of this month');
        $baskets = $this->baskets($month, $end);
        $weeks = array_map(static fn (Basket $b): \DateTimeImmutable => new \DateTimeImmutable($b->getDate()->format('Y-m-d')), $baskets);
        $shares = $this->shares($month, $end);

        return self::compute(
            $month,
            $weeks,
            $shares,
            $this->rates->schedule(),
            $this->adjustments->findApplyingTo($month),
            $this->stopEvents($end),
            $this->pauses->findTouching($month, $end),
            $this->newHouseholds($month, $end),
            $this->workers(),
            $this->deliveriesOfLateStarts($baskets, $weeks, $shares),
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
     * @param list<int>                 $newHouseholds Ids de los hogares (quien encabeza la familia) que pagan la inscripción este mes.
     * @param list<Worker>              $workers     Trabajadorxs con ficha de socix.
     * @param array<int, list<string>>  $deliveries  Por id de cesta, las semanas (Y-m-d) en que la recibe: sólo
     *                                               las que salen en el patrón del reparto ({@see self::isProjected()}).
     */
    public static function compute(\DateTimeImmutable $month, array $weeks, array $shares, FeeSchedule $schedule, array $adjustments, array $events, array $pauses = [], array $newHouseholds = [], array $workers = [], array $deliveries = []): MonthlyFees
    {
        // Qué fichas tenían cesta en el primer reparto del mes y cuáles empiezan otra después.
        $hadBasket = $startsAnother = [];
        foreach ($weeks !== [] ? $shares : [] as $share) {
            $key = MonthlyFees::keyOf($share->getPartner());
            if (self::holds($share, $weeks[0])) {
                $hadBasket[$key] = true;
            }
            if (self::startsLate($share, $weeks)) {
                $startsAnother[$key] = true;
            }
        }
        $workerOf = [];
        foreach ($workers as $worker) {
            if ($worker->getPartner() !== null) {
                $workerOf[MonthlyFees::keyOf($worker->getPartner())] = $worker;
            }
        }
        $stops = self::stopPeriods($events);
        foreach ($pauses as $pause) {
            $stops[MonthlyFees::keyOf($pause->getPartner())][] = [$pause->getStartsOn(), $pause->getResumesOn()];
        }
        $statements = [];
        $statementFor = static function (Partner $payer) use (&$statements): FeeStatement {
            return $statements[MonthlyFees::keyOf($payer)] ??= new FeeStatement($payer);
        };

        /** @var array<string, array{0: Partner, 1: FeeStatement, 2: bool}> $households hogar, recibo de su primera cesta del mes, si alguna se cobra */
        $households = [];
        foreach ($shares as $share) {
            $partner = $share->getPartner();
            $own = MonthlyFees::keyOf($partner);
            // Sólo las que salen en el patrón tienen sus cestas (y sólo ellas tienen que estar guardadas).
            $received = $share->getId() !== null && self::isProjected($share, $weeks) ? ($deliveries[$share->getId()] ?? null) : null;
            $charge = self::charge($share, $weeks, $stops[$own] ?? [], isset($hadBasket[$own]), isset($startsAnother[$own]), $received);
            $household = $partner->getFamilyHead();
            // Un alta que este mes no recibe nada sólo deja su línea (a cero) si el hogar
            // empieza ahora: es la que lleva su inscripción. Si no, no hay nada que cobrar.
            if ($charge === null || ($charge[0] === [] && !\in_array($household->getId(), $newHouseholds, true))) {
                continue;
            }
            $line = self::line($share, $charge[0], $charge[1], $charge[2], $schedule, $workerOf[$own] ?? null);
            $statement = $statementFor($share->getEffectivePayer()->getFamilyHead());
            $statement->addLine($line);

            $key = MonthlyFees::keyOf($household);
            $households[$key] ??= [$household, $statement, false];
            $households[$key][2] = $households[$key][2] || !$line->isFree();
        }

        // Las cuotas de socix, una vez por hogar, en el recibo de su primera cesta del mes.
        // Un hogar cuyas cestas del mes son todas de trabajador, gratis, no las paga.
        foreach ($households as [$household, $statement, $paying]) {
            if (!$paying) {
                continue;
            }
            foreach (self::membershipConcepts($month, \in_array($household->getId(), $newHouseholds, true)) as $concept) {
                $price = $schedule->rateOn($concept, $month)?->getPrice();
                $statement->addCharge(new FeeCharge($concept, $household, $price !== null ? IssuedInvoice::toCents($price) : null));
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
     * Las cuotas de socix que tocan a un hogar con cesta este mes: la de inscripción si
     * su primera cesta empieza este mes, la de mantenimiento si es junio o diciembre.
     *
     * @return list<FeeConcept>
     */
    private static function membershipConcepts(\DateTimeImmutable $month, bool $isNew): array
    {
        return array_values(array_filter([
            $isNew ? FeeConcept::MembershipSignup : null,
            \in_array((int) $month->format('n'), FeeConcept::UPKEEP_MONTHS, true) ? FeeConcept::MembershipUpkeep : null,
        ]));
    }

    /**
     * Qué semanas se cobran de una cesta este mes y entre cuántas partes se reparte la
     * cuota mensual: [semanas, partes, si es medio mes]. Null si no se cobra nada; sin
     * semanas, un alta que este mes no recibe nada (quien llama decide si deja su línea:
     * sólo para la inscripción).
     *
     * @param list<\DateTimeImmutable>                                   $weeks
     * @param list<array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}> $stops         Periodos sin cobrar [desde, hasta).
     * @param bool                                                       $hadBasket     Si su ficha tenía cesta en el primer reparto del mes.
     * @param bool                                                       $startsAnother Si su ficha empieza otra cesta después del primer reparto.
     * @param list<string>|null                                          $received      Semanas (Y-m-d) en que la recibe, si sale en el patrón del reparto.
     *
     * @return array{0: list<\DateTimeImmutable>, 1: int, 2: bool}|null
     */
    private static function charge(PartnerBasketShare $share, array $weeks, array $stops, bool $hadBasket, bool $startsAnother, ?array $received): ?array
    {
        if ($weeks === []) {
            return null;
        }
        $running = static fn (\DateTimeImmutable $w): bool => !self::stopped($stops, $w);
        $wholeMonth = array_values(array_filter($weeks, $running));
        // Las semanas en que la tiene y no está parada; las claves son su posición en el mes.
        $held = array_filter($weeks, static fn (\DateTimeImmutable $w): bool => self::holds($share, $w) && $running($w));

        if (!self::startsLate($share, $weeks)) {
            // La que tenía al empezar el mes se paga entera si la cambia por otra.
            $charged = $startsAnother && self::holds($share, $weeks[0]) ? $wholeMonth : array_values($held);

            return $charged === [] ? null : [$charged, \count($weeks), false];
        }
        if ($hadBasket) {
            return null; // Cambio: la nueva cuenta desde el mes siguiente.
        }

        // Alta a mitad: lo decide su primera cesta del mes. Sin ninguna semana que la
        // tenga (empieza tras el último reparto, o en pausa) no hay nada: la inscripción
        // va con su primer reparto ({@see self::newHouseholds()}).
        if ($held === []) {
            return null;
        }
        $perMonth = $share->getBasketShare()?->getDeliveriesPerMonth();
        $first = array_key_first(array_filter($held, static fn (\DateTimeImmutable $w): bool => $received === null || \in_array($w->format('Y-m-d'), $received, true)));
        if ($first === null) {
            return [[], $perMonth ?? \count($weeks), false];
        }
        // Una semanal siempre ha perdido alguna; una quincenal, si su turno ya tuvo una antes.
        $missedOne = $perMonth === null || ($perMonth === 2 && $first >= 2);

        return $missedOne ? [[$weeks[$first]], 2, true] : [$wholeMonth, \count($weeks), false];
    }

    /**
     * Lo que cuesta una cesta en el mes: la suma de las cuotas mensuales de las semanas
     * que se cobran, entre las partes del mes.
     *
     * @param list<\DateTimeImmutable> $active    Semanas que se cobran (las de {@see self::charge()}).
     * @param int                      $parts     Entre cuántas partes iguales se reparte la cuota mensual.
     * @param bool                     $halfMonth Si es el medio mes de un alta a mitad.
     * @param Worker|null              $worker    Si la cesta es de alguien que trabaja o trabajó en la asociación.
     */
    private static function line(PartnerBasketShare $share, array $active, int $parts, bool $halfMonth, FeeSchedule $schedule, ?Worker $worker = null): FeeLine
    {
        // Las semanas gratis cuentan como suyas, pero no se cobran.
        $kinds = array_map(static fn (\DateTimeImmutable $w): ?WorkerBasketWeek => $worker?->basketWeek($w), $active);
        $charged = array_values(array_filter($active, static fn (\DateTimeImmutable $w, int $i): bool => $kinds[$i] !== WorkerBasketWeek::Free, \ARRAY_FILTER_USE_BOTH));

        $missing = [];
        $veg = $egg = $transport = 0;
        $basketShare = $share->getBasketShare();
        $group = $share->getPartner()?->getWeeklyBasketGroup();
        $eggAmount = $share->getEggAmount();
        $eggPeriod = $share->getEggPeriod();
        foreach ($charged as $week) {
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

        // Se redondea una vez.
        return new FeeLine(
            $share,
            (int) round($veg / $parts),
            (int) round($egg / $parts),
            (int) round($transport / $parts),
            \count($active),
            $parts,
            array_values(array_unique(array_filter($missing), \SORT_REGULAR)),
            \count($active) - \count($charged),
            \count(array_keys($kinds, WorkerBasketWeek::Undecided, true)),
            $halfMonth,
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
     * Si una cesta empieza después del primer reparto del mes: es un cambio o un alta a
     * mitad.
     *
     * @param list<\DateTimeImmutable> $weeks
     */
    private static function startsLate(PartnerBasketShare $share, array $weeks): bool
    {
        $start = $share->getStartDate();

        return $weeks !== [] && $start !== null && $start->format('Y-m-d') > $weeks[0]->format('Y-m-d');
    }

    /**
     * Si hace falta saber qué cestas le da el patrón del reparto: una quincenal o una
     * mensual, aún abierta, que empieza a mitad de mes. Las cerradas no salen en el
     * patrón, que sólo mira las abiertas.
     *
     * @param list<\DateTimeImmutable> $weeks
     */
    private static function isProjected(PartnerBasketShare $share, array $weeks): bool
    {
        return self::startsLate($share, $weeks)
            && $share->getIsActive()
            && $share->getBasketShare()?->getDeliveriesPerMonth() !== null;
    }

    /**
     * Las semanas en que recibe su cesta cada una de las que empiezan a medias
     * ({@see self::isProjected()}), según el patrón del reparto. Si no hay ninguna,
     * no se pregunta nada.
     *
     * @param list<Basket>              $baskets
     * @param list<\DateTimeImmutable>  $weeks   Las fechas de esos repartos, en el mismo orden.
     * @param list<PartnerBasketShare>  $shares
     *
     * @return array<int, list<string>> Por id de cesta, las semanas (Y-m-d).
     */
    private function deliveriesOfLateStarts(array $baskets, array $weeks, array $shares): array
    {
        $deliveries = [];
        foreach ($shares as $share) {
            if (self::isProjected($share, $weeks)) {
                $deliveries[$share->getId()] = [];
            }
        }
        if ($deliveries === []) {
            return [];
        }
        foreach ($baskets as $i => $basket) {
            foreach ($this->generator->projectForBasket($basket) as $candidate) {
                if (isset($deliveries[$candidate->getId()])) {
                    $deliveries[$candidate->getId()][] = $weeks[$i]->format('Y-m-d');
                }
            }
        }

        return $deliveries;
    }

    /**
     * Los repartos del mes: uno por ciclo semanal global, en orden.
     *
     * @return list<Basket>
     */
    private function baskets(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->em->createQueryBuilder()
            ->select('b')->from(Basket::class, 'b')
            ->andWhere('b.date BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()->getResult();
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
            ->orderBy('s.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Los hogares que pagan la inscripción este mes: aquellos cuya primera cesta (la
     * más antigua de todas las de su familia) tiene este mes su primer reparto. Casi
     * siempre es el mes en que empieza; pero una cesta que empieza después del último
     * reparto de un mes tiene el primero en el mes siguiente, y es entonces cuando se
     * cobra (antes no tenía ninguna semana que cobrar y la inscripción se perdía). En
     * una consulta. Un hogar con alguna cesta sin fecha es de antes de que se
     * guardaran: nunca es nuevo.
     *
     * @return list<int> Ids de quien encabeza cada familia.
     */
    private function newHouseholds(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $previous = $start->modify('-1 month');

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT h.household
               FROM (SELECT COALESCE(p.parent_id, p.id) AS household, MIN(s.start_date) AS first_start
                       FROM partner_basket_share s JOIN partner p ON p.id = s.partner_id
                      GROUP BY household
                     HAVING SUM(s.start_date IS NULL) = 0) h
              WHERE h.first_start BETWEEN :previous AND :end
                AND (h.first_start >= :start
                     OR NOT EXISTS (SELECT 1 FROM basket b WHERE b.date BETWEEN h.first_start AND :previousEnd))',
            [
                'previous' => $previous->format('Y-m-d'),
                'previousEnd' => $start->modify('-1 day')->format('Y-m-d'),
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ],
        ));
    }

    /**
     * Quienes trabajan o trabajaron en la asociación y tienen ficha de socix, con ella.
     *
     * @return list<Worker>
     */
    private function workers(): array
    {
        return $this->em->createQueryBuilder()
            ->select('w', 'p')->from(Worker::class, 'w')
            ->join('w.partner', 'p')
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
