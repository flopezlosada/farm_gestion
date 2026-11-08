<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\IssuedInvoice;
use App\Entity\Remittance;
use App\Entity\RemittanceReturn;
use App\Repository\AccountEntryRepository;
use App\Repository\RemittanceRepository;
use App\Repository\RemittanceReturnRepository;

/**
 * Lo que de verdad abonó el banco por cada remesa enviada, y si cuadra con lo que se
 * pidió.
 *
 * Fiare abona una remesa en uno o varios lotes `ab.rem.<ref>` EL MISMO DÍA (en
 * septiembre de 2026, 150,00 y 7.437,40 el día 9). Otros días del mes puede haber
 * cobros sueltos que no son la remesa (97,00 el 4 de agosto). Por eso los lotes de una
 * remesa son los abonos del día de su periodo cuya suma más se acerca a lo pedido:
 * alrededor del día de cargo si se sabe, si no en su mes (se cobra el mes en curso).
 * Y sólo cuando el libro ya pasa de ese periodo, como en {@see RemittanceChargeWatch}:
 * antes, un cobro suelto anterior al abono de verdad parecería la remesa entera con
 * casi todo por cobrar.
 *
 * Lo pedido menos lo abonado son los recibos que el banco rechazó ANTES de cobrarlos
 * (cuenta mal, mandato…): no traen apunte de devolución, sólo faltan del abono. Se
 * descuentan los que ya se han dado por devueltos sin apunte; lo que queda es lo que
 * falta por identificar.
 */
class RemittanceCredits
{
    public function __construct(
        private readonly RemittanceRepository $remittances,
        private readonly AccountEntryRepository $entries,
        private readonly RemittanceReturnRepository $returns,
        private readonly RemittanceChargeWatch $chargeWatch,
    ) {
    }

    /**
     * Las remesas enviadas cuyo abono no cuadra con lo pedido, con la diferencia.
     * Las que aún no tienen abono en el libro no salen: eso lo vigila
     * {@see RemittanceChargeWatch}.
     *
     * @return list<array{remittance: Remittance, lots: list<AccountEntry>, missingCents: int}>
     */
    public function mismatches(): array
    {
        $rows = [];
        $rejected = $this->returns->findBy(['entry' => null]);
        foreach ($this->lotsOfSent() as ['remittance' => $remittance, 'lots' => $lots, 'settled' => $settled]) {
            $missing = self::missingCents($remittance, $lots, $rejected);
            if ($settled && $lots !== [] && $missing !== 0) {
                $rows[] = ['remittance' => $remittance, 'lots' => $lots, 'missingCents' => $missing];
            }
        }

        return $rows;
    }

    /**
     * El cuadre de una remesa con el banco, para su ficha: sus lotes y lo que falta.
     * Null si no está enviada, si el abono aún no está en el libro o si el libro aún no
     * pasa del periodo en que puede entrar (puede faltar algún lote).
     *
     * @return array{lots: list<AccountEntry>, missingCents: int}|null
     */
    public function checkFor(Remittance $remittance): ?array
    {
        $settled = $remittance->isSent() && self::isSettled(self::range($remittance)[1], $this->chargeWatch->bookUpTo());
        $lots = $settled ? $this->lotsFor($remittance) : [];
        if ($lots === []) {
            return null;
        }

        return [
            'lots' => $lots,
            'missingCents' => self::missingCents($remittance, $lots, $this->returns->findBy(['entry' => null])),
        ];
    }

    /**
     * Los lotes del banco de una remesa.
     *
     * @return list<AccountEntry>
     */
    public function lotsFor(Remittance $remittance): array
    {
        [$from, $to] = self::range($remittance);

        return self::pickLots($this->entries->findByConceptPrefix(BankReturns::CREDIT_PREFIX, $from, $to), $from, $to, $remittance);
    }

    /**
     * Las devoluciones del banco que aún no se han ligado a ningún recibo y son de
     * alguna remesa de aquí: las de un cobro suelto (otro lote del mes) no tienen recibo
     * con el que ligarse y no se piden.
     *
     * @return list<AccountEntry>
     */
    public function unidentifiedReturns(): array
    {
        $from = $this->remittances->firstPeriod();
        if ($from === null) {
            return [];
        }
        $references = [];
        foreach ($this->lotsOfSent() as ['lots' => $lots]) {
            foreach ($lots as $lot) {
                $references[BankReturns::reference($lot->getConcept(), BankReturns::CREDIT_PREFIX)] = true;
            }
        }

        return array_values(array_filter(
            $this->entries->findUnlinkedReturnCharges(BankReturns::RETURN_PREFIX, $from),
            static fn (AccountEntry $e): bool => isset($references[BankReturns::reference($e->getConcept(), BankReturns::RETURN_PREFIX)]),
        ));
    }

    /**
     * Lo pedido menos lo abonado menos lo ya dado por devuelto sin apunte, en
     * céntimos. Positivo: faltan recibos rechazados por identificar. Negativo: entró
     * más de lo pedido. Puro, sin base de datos.
     *
     * @param list<AccountEntry>     $lots     Los lotes del banco de la remesa.
     * @param list<RemittanceReturn> $rejected Devoluciones de cualquier remesa; sólo cuentan las de ésta sin apunte.
     */
    public static function missingCents(Remittance $remittance, array $lots, array $rejected): int
    {
        $credited = array_sum(array_map(static fn (AccountEntry $e): int => IssuedInvoice::toCents($e->getAmount()), $lots));
        $marked = array_sum(array_map(
            static fn (RemittanceReturn $r): int => IssuedInvoice::toCents($r->getLine()->getAmount()),
            array_filter($rejected, static fn (RemittanceReturn $r): bool => $r->getEntry() === null && $r->getLine()->getRemittance() === $remittance),
        ));

        return IssuedInvoice::toCents($remittance->getTotal()) - $credited - $marked;
    }

    /**
     * De los abonos de remesa de un periodo, los del día cuya suma más se acerca a lo
     * pedido en la remesa. Puro.
     *
     * @param list<AccountEntry> $credits Apuntes del libro; sólo cuentan los abonos de remesa dentro del periodo.
     *
     * @return list<AccountEntry>
     */
    public static function pickLots(array $credits, \DateTimeImmutable $from, \DateTimeImmutable $to, Remittance $remittance): array
    {
        return self::pickDay(
            $credits, $from, $to, $remittance,
            static fn (AccountEntry $e): ?\DateTimeInterface => $e->getDate(),
            static fn (AccountEntry $e): string => $e->getConcept(),
            static fn (AccountEntry $e): int => IssuedInvoice::toCents($e->getAmount()),
        );
    }

    /**
     * Lo mismo que {@see pickLots()} con los movimientos del banco, antes de que tengan
     * apunte: así el abono se anota sabiendo de qué remesa es. Puro.
     *
     * @param list<BankStatementLine> $lines Movimientos del banco; sólo cuentan los abonos de remesa dentro del periodo.
     *
     * @return list<BankStatementLine>
     */
    public static function pickLines(array $lines, \DateTimeImmutable $from, \DateTimeImmutable $to, Remittance $remittance): array
    {
        return self::pickDay(
            $lines, $from, $to, $remittance,
            static fn (BankStatementLine $l): \DateTimeInterface => $l->getOperationDate(),
            static fn (BankStatementLine $l): string => $l->getSuggestedConcept(),
            static fn (BankStatementLine $l): int => $l->getAmountCents(),
        );
    }

    /**
     * Los abonos de remesa del día del periodo cuya suma más se acerca a lo pedido; a
     * igual distancia, el primer día.
     *
     * @template T of object
     *
     * @param list<T>                              $credits
     * @param callable(T): ?\DateTimeInterface     $date
     * @param callable(T): string                  $concept
     * @param callable(T): int                     $cents
     *
     * @return list<T>
     */
    private static function pickDay(array $credits, \DateTimeImmutable $from, \DateTimeImmutable $to, Remittance $remittance, callable $date, callable $concept, callable $cents): array
    {
        $byDay = [];
        foreach ($credits as $credit) {
            $day = $date($credit)?->format('Y-m-d');
            if ($day !== null
                && BankReturns::reference($concept($credit), BankReturns::CREDIT_PREFIX) !== null
                && $day >= $from->format('Y-m-d') && $day <= $to->format('Y-m-d')) {
                $byDay[$day][] = $credit;
            }
        }
        if ($byDay === []) {
            return [];
        }
        $asked = IssuedInvoice::toCents($remittance->getTotal());
        $distance = static fn (array $lots): int => abs($asked - array_sum(array_map($cents, $lots)));
        ksort($byDay);
        $best = null;
        foreach ($byDay as $lots) {
            if ($best === null || $distance($lots) < $distance($best)) {
                $best = $lots;
            }
        }

        return $best;
    }

    /**
     * Dónde se busca el abono de una remesa: el margen de su día de cargo si se sabe;
     * si no, su mes.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function range(Remittance $remittance): array
    {
        if ($remittance->getChargeDate() !== null) {
            return RemittanceChargeWatch::window($remittance->getChargeDate());
        }
        $first = Remittance::firstOfMonth($remittance->getPeriod());

        return [$first, $first->modify('last day of this month')];
    }

    /**
     * Las remesas enviadas con sus lotes, con una sola consulta de abonos para todas.
     *
     * @return list<array{remittance: Remittance, lots: list<AccountEntry>, settled: bool}>
     */
    private function lotsOfSent(): array
    {
        $sent = array_values(array_filter($this->remittances->findAllNewestFirst(), static fn (Remittance $r): bool => $r->isSent()));
        if ($sent === []) {
            return [];
        }
        $ranges = array_map(self::range(...), $sent);
        $bookUpTo = $this->chargeWatch->bookUpTo();
        $credits = $this->entries->findByConceptPrefix(
            BankReturns::CREDIT_PREFIX,
            min(array_column($ranges, 0)),
            max(array_column($ranges, 1)),
        );

        return array_map(
            static fn (Remittance $r, array $range): array => [
                'remittance' => $r,
                'lots' => self::pickLots($credits, $range[0], $range[1], $r),
                'settled' => self::isSettled($range[1], $bookUpTo),
            ],
            $sent,
            $ranges,
        );
    }

    /**
     * Si el libro ya pasa del último día en que puede entrar el abono: hasta entonces
     * puede faltar algún lote, y lo que haya no se juzga.
     *
     * @param \DateTimeImmutable      $to       Último día del periodo del abono.
     * @param \DateTimeImmutable|null $bookUpTo Hasta dónde llega el libro; null si no se sabe.
     */
    public static function isSettled(\DateTimeImmutable $to, ?\DateTimeImmutable $bookUpTo): bool
    {
        return $bookUpTo !== null && $bookUpTo->format('Y-m-d') > $to->format('Y-m-d');
    }
}
