<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\AccountEntry;
use App\Entity\Remittance;
use App\Repository\AccountEntryRepository;
use App\Repository\RemittanceRepository;

/**
 * Si las remesas enviadas han entrado en el banco, mirando el libro.
 *
 * Una remesa se carga el día que se pidió al subirla (o el siguiente hábil, si cae en
 * festivo) y Fiare la abona en una línea, `ab.rem.<ref>` ({@see BankReturns}). Si
 * entre dos días hábiles antes y dos después no hay ningún abono de remesa, y el libro
 * de la cuenta donde entran ya llega más allá, es que no se cobró: el banco la
 * rechazó entera o se quedó pendiente de firma. Si el libro no llega, no se sabe y no
 * se avisa: con el extracto atrasado todas las remesas parecerían sin cobrar.
 *
 * Sólo se mira la fecha de cargo, no el mes de la remesa: lo que se cobra (un mes) y
 * cuándo se carga son cosas distintas. Tampoco el importe: un abono de remesa en esos
 * días la da por entrada, aunque sea por menos.
 *
 * Además propone el día de cargo al enviarla: el que se suele usar, sacado de los
 * últimos abonos de remesa del libro.
 */
class RemittanceChargeWatch
{
    /** Días hábiles a cada lado del de cargo en que puede aparecer el abono. */
    public const MARGIN_BUSINESS_DAYS = 2;

    /** Cuántos de los últimos abonos de remesa dicen el día de cargo habitual. */
    public const USUAL_DAY_SAMPLE = 6;

    public function __construct(
        private readonly RemittanceRepository $remittances,
        private readonly AccountEntryRepository $entries,
    ) {
    }

    /**
     * Las remesas enviadas que no han entrado en el banco, con hasta dónde llega el
     * libro de la cuenta en que entran las remesas. Vacío si no hay ninguna vigilada
     * o si el libro no tiene ningún abono de remesa con el que saber cuál es esa cuenta.
     *
     * @return list<array{remittance: Remittance, bookUpTo: \DateTimeImmutable}>
     */
    public function uncredited(): array
    {
        $watched = $this->remittances->findSentWithChargeDate();
        if ($watched === []) {
            return [];
        }
        $latest = self::credits($this->entries->findLatestByConceptPrefix(BankReturns::CREDIT_PREFIX, self::USUAL_DAY_SAMPLE));
        $accountId = $latest !== [] ? $latest[0]->getAccount()?->getId() : null;
        $bookUpTo = $accountId !== null ? ($this->entries->lastDateByAccount()[$accountId] ?? null) : null;
        if ($bookUpTo === null) {
            return [];
        }

        $windows = array_map(self::window(...), array_values(array_filter(array_map(
            static fn (Remittance $r): ?\DateTimeImmutable => $r->getChargeDate(),
            $watched,
        ))));
        if ($windows === []) {
            return [];
        }
        $credits = $this->entries->findByConceptPrefix(
            BankReturns::CREDIT_PREFIX,
            min(array_column($windows, 0)),
            max(array_column($windows, 1)),
        );

        return array_map(
            static fn (Remittance $r): array => ['remittance' => $r, 'bookUpTo' => $bookUpTo],
            self::findUncredited($watched, $credits, $bookUpTo),
        );
    }

    /**
     * El día de cargo que se propone al enviar una remesa: el siguiente al de hoy que
     * cae en el día del mes habitual. Null si el libro no tiene abonos de remesa de los
     * que sacarlo.
     *
     * @param \DateTimeImmutable $today Hoy.
     */
    public function usualChargeDate(\DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $day = self::usualDay($this->entries->findLatestByConceptPrefix(BankReturns::CREDIT_PREFIX, self::USUAL_DAY_SAMPLE));

        return $day !== null ? self::nextChargeDate($day, $today) : null;
    }

    /**
     * De unas remesas, las enviadas con día de cargo sin ningún abono de remesa en su
     * margen y con el libro llegando más allá de él. Puro, sin base de datos.
     *
     * @param list<Remittance>   $remittances Las que se miran; las demás se ignoran.
     * @param list<AccountEntry> $entries     Apuntes del libro; sólo cuentan los abonos de remesa.
     * @param \DateTimeImmutable $bookUpTo    El último apunte de la cuenta donde entran las remesas.
     *
     * @return list<Remittance>
     */
    public static function findUncredited(array $remittances, array $entries, \DateTimeImmutable $bookUpTo): array
    {
        $creditDays = array_map(static fn (AccountEntry $e): string => self::day($e)->format('Y-m-d'), self::credits($entries));
        $bookUpTo = $bookUpTo->setTime(0, 0);

        return array_values(array_filter($remittances, static function (Remittance $r) use ($creditDays, $bookUpTo): bool {
            if (!$r->isSent() || $r->getChargeDate() === null) {
                return false;
            }
            [$from, $to] = self::window($r->getChargeDate());
            $inWindow = array_filter($creditDays, static fn (string $d): bool => $d >= $from->format('Y-m-d') && $d <= $to->format('Y-m-d'));

            return $bookUpTo > $to && $inWindow === [];
        }));
    }

    /**
     * Desde y hasta qué día puede aparecer el abono de una remesa pedida para esa
     * fecha: dos días hábiles a cada lado del día en que de verdad se carga.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function window(\DateTimeImmutable $chargeDate): array
    {
        $day = BankBusinessDays::nextOrSame($chargeDate);

        return [
            BankBusinessDays::add($day, -self::MARGIN_BUSINESS_DAYS),
            BankBusinessDays::add($day, self::MARGIN_BUSINESS_DAYS),
        ];
    }

    /**
     * El día del mes en que se suelen cargar las remesas: la mediana (la de abajo, si
     * son pares) del de unos abonos de remesa. Así un cobro suelto otro día no lo
     * mueve. Null si no hay ninguno.
     *
     * @param list<AccountEntry> $entries Apuntes del libro; sólo cuentan los abonos de remesa.
     */
    public static function usualDay(array $entries): ?int
    {
        $days = array_map(static fn (AccountEntry $e): int => (int) self::day($e)->format('j'), self::credits($entries));
        if ($days === []) {
            return null;
        }
        sort($days);

        return $days[intdiv(\count($days) - 1, 2)];
    }

    /**
     * El primer día hábil después de hoy que cae en ese día del mes: este mes si aún
     * no ha pasado y si no el siguiente. En un mes más corto, su último día.
     *
     * @param int                $day   Día del mes, 1-31.
     * @param \DateTimeImmutable $today Hoy.
     */
    public static function nextChargeDate(int $day, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $today = $today->setTime(0, 0);
        $thisMonth = self::dayInMonth(Remittance::firstOfMonth($today), $day);

        return $thisMonth > $today
            ? $thisMonth
            : self::dayInMonth(Remittance::firstOfMonth($today)->modify('+1 month'), $day);
    }

    /**
     * Ese día del mes que empieza en `$first` (su último día, si el mes es más corto),
     * o el siguiente hábil.
     */
    private static function dayInMonth(\DateTimeImmutable $first, int $day): \DateTimeImmutable
    {
        return BankBusinessDays::nextOrSame($first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('n'),
            min(max($day, 1), (int) $first->format('t')),
        ));
    }

    /**
     * De unos apuntes, los abonos de remesa con fecha: los que reconoce
     * {@see BankReturns::reference()}, que es lo que pone Fiare.
     *
     * @param list<AccountEntry> $entries
     *
     * @return list<AccountEntry>
     */
    private static function credits(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            static fn (AccountEntry $e): bool => $e->getDate() !== null && BankReturns::reference($e->getConcept(), BankReturns::CREDIT_PREFIX) !== null,
        ));
    }

    /** El día de un apunte, a medianoche. */
    private static function day(AccountEntry $entry): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($entry->getDate())->setTime(0, 0);
    }
}
