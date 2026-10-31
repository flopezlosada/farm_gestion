<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\IssuedInvoice;
use App\Repository\AccountEntryRepository;

/**
 * Empareja los movimientos del banco con los apuntes del libro que los anotan. Sólo por
 * reglas que se pueden explicar, nunca por el texto ni por un importe parecido:
 *
 *  - misma cuenta y el MISMO importe, con signo, al céntimo;
 *  - el apunte no explica ya otro movimiento;
 *  - fechas a ±{@see SUGGEST_DAYS} días naturales.
 *
 * Se liga solo cuando no hay duda:
 *
 *  - el MISMO día hay tantos movimientos de un importe como apuntes de ese importe (dos
 *    transferencias de 10 € el día 15 en el banco y dos en el libro): se ligan entre sí
 *    en orden. Dentro del grupo son indistinguibles por fecha e importe, y fuera del día
 *    no se cruza nada;
 *  - o un único candidato, a ±{@see AUTO_DAYS} días, y ese apunte no es candidato de
 *    ningún otro movimiento.
 *
 * Si no, se propone y lo confirma una persona con un botón. Así, dos cargos iguales en
 * días seguidos (dos recibos de la luz) nunca se cruzan por casualidad.
 *
 * Una remesa cobrada de golpe, la liquidación de la tarjeta o una devolución pueden ser
 * varios apuntes para un solo movimiento: eso no se intenta, se marca para revisarlo a
 * mano ({@see BankStatementLine::isGrouped}).
 */
class BankLineMatcher
{
    /** Días de diferencia hasta los que un candidato único se liga solo. */
    public const AUTO_DAYS = 3;

    /** Días de diferencia hasta los que un apunte se propone. */
    public const SUGGEST_DAYS = 7;

    public function __construct(private readonly AccountEntryRepository $entries)
    {
    }

    /**
     * Los apuntes que pueden anotar cada movimiento, los más cercanos en fecha primero.
     * Carga los apuntes en UNA consulta para todos los movimientos.
     *
     * @param list<BankStatementLine> $lines Movimientos sin apunte.
     *
     * @return list<array{line: BankStatementLine, candidates: list<AccountEntry>}> En el orden de $lines.
     */
    public function candidatesFor(array $lines): array
    {
        return self::candidates($lines, $this->loadFor($lines));
    }

    /**
     * Liga los movimientos que no dejan duda y devuelve cuántos. No guarda: lo hace
     * quien llama, con lo demás.
     *
     * @param list<BankStatementLine> $lines Movimientos sin apunte.
     */
    public function linkSafe(array $lines): int
    {
        $pairs = self::safePairs($lines, $this->loadFor($lines));
        foreach ($pairs as [$line, $entry]) {
            $line->linkTo($entry);
        }

        return \count($pairs);
    }

    /**
     * Si un apunte puede anotar un movimiento: lo que se comprueba al confirmar a mano.
     * El apunte no debe explicar ya otro movimiento; eso lo mira quien llama.
     */
    public static function isCandidate(BankStatementLine $line, AccountEntry $entry): bool
    {
        return $line->getEntry() === null
            && $entry->getAccount() === $line->getAccount()
            && IssuedInvoice::toCents($entry->getAmount()) === $line->getAmountCents()
            && self::days($line, $entry) <= self::SUGGEST_DAYS;
    }

    /**
     * Los candidatos de cada movimiento entre unos apuntes dados (sin base de datos).
     *
     * @param list<BankStatementLine> $lines
     * @param list<AccountEntry>      $entries Apuntes que no explican ningún movimiento.
     *
     * @return list<array{line: BankStatementLine, candidates: list<AccountEntry>}>
     */
    public static function candidates(array $lines, array $entries): array
    {
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[self::key($entry->getAccount()?->getId(), IssuedInvoice::toCents($entry->getAmount()))][] = $entry;
        }

        return array_map(static function (BankStatementLine $line) use ($byKey): array {
            $near = array_values(array_filter(
                $byKey[self::key($line->getAccount()->getId(), $line->getAmountCents())] ?? [],
                static fn (AccountEntry $e): bool => $e->getAccount() === $line->getAccount() && self::days($line, $e) <= self::SUGGEST_DAYS,
            ));
            usort($near, static fn (AccountEntry $a, AccountEntry $b): int => [self::days($line, $a), $a->getId()] <=> [self::days($line, $b), $b->getId()]);

            return ['line' => $line, 'candidates' => $near];
        }, $lines);
    }

    /**
     * Las parejas que se ligan solas: primero los grupos del mismo día con tantos
     * movimientos como apuntes; con lo que queda, el movimiento con un único candidato,
     * a ±{@see AUTO_DAYS} días, que sólo es candidato de ese movimiento.
     *
     * @param list<BankStatementLine> $lines
     * @param list<AccountEntry>      $entries
     *
     * @return list<array{0: BankStatementLine, 1: AccountEntry}>
     */
    public static function safePairs(array $lines, array $entries): array
    {
        $sameDay = self::sameDayPairs($lines, $entries);
        $paired = [];
        foreach ($sameDay as [$line, $entry]) {
            $paired[spl_object_id($line)] = $paired[spl_object_id($entry)] = true;
        }
        $rest = static fn (object $o): bool => !isset($paired[spl_object_id($o)]);
        $found = self::candidates(array_values(array_filter($lines, $rest)), array_values(array_filter($entries, $rest)));
        $claims = [];
        foreach ($found as $row) {
            foreach ($row['candidates'] as $entry) {
                $claims[spl_object_id($entry)] = ($claims[spl_object_id($entry)] ?? 0) + 1;
            }
        }

        return [...$sameDay, ...array_values(array_map(
            static fn (array $row): array => [$row['line'], $row['candidates'][0]],
            array_filter($found, static fn (array $row): bool => \count($row['candidates']) === 1
                && self::days($row['line'], $row['candidates'][0]) <= self::AUTO_DAYS
                && $claims[spl_object_id($row['candidates'][0])] === 1),
        ))];
    }

    /**
     * Los grupos de misma cuenta, mismo importe y mismo día con tantos movimientos como
     * apuntes, emparejados en orden: los movimientos como vienen, los apuntes por id
     * (el orden en que se anotaron).
     *
     * @param list<BankStatementLine> $lines
     * @param list<AccountEntry>      $entries
     *
     * @return list<array{0: BankStatementLine, 1: AccountEntry}>
     */
    private static function sameDayPairs(array $lines, array $entries): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $groups[self::dayKey($line->getAccount()->getId(), $line->getAmountCents(), $line->getOperationDate())]['lines'][] = $line;
        }
        foreach ($entries as $entry) {
            $key = self::dayKey($entry->getAccount()?->getId(), IssuedInvoice::toCents($entry->getAmount()), $entry->getDate());
            if (isset($groups[$key])) {
                $groups[$key]['entries'][] = $entry;
            }
        }

        $pairs = [];
        foreach ($groups as $group) {
            $dayEntries = $group['entries'] ?? [];
            if (\count($dayEntries) !== \count($group['lines'])) {
                continue;
            }
            usort($dayEntries, static fn (AccountEntry $a, AccountEntry $b): int => $a->getId() <=> $b->getId());
            array_push($pairs, ...array_map(null, $group['lines'], $dayEntries));
        }

        return $pairs;
    }

    /**
     * Los apuntes sin movimiento que podrían casar con alguno de éstos: de sus cuentas,
     * de sus importes y en su ventana de fechas.
     *
     * @param list<BankStatementLine> $lines
     *
     * @return list<AccountEntry>
     */
    private function loadFor(array $lines): array
    {
        if ($lines === []) {
            return [];
        }
        $dates = array_map(static fn (BankStatementLine $l): \DateTimeImmutable => $l->getOperationDate(), $lines);
        $window = sprintf('%d days', self::SUGGEST_DAYS);
        $accounts = [];
        foreach ($lines as $line) {
            $accounts[spl_object_id($line->getAccount())] = $line->getAccount();
        }

        return $this->entries->findWithoutBankLine(
            array_values($accounts),
            min($dates)->modify('-'.$window),
            max($dates)->modify('+'.$window),
            array_values(array_unique(array_map(static fn (BankStatementLine $l): string => $l->getAmount(), $lines))),
        );
    }

    /** Días naturales entre el movimiento y el apunte, sin signo. */
    private static function days(BankStatementLine $line, AccountEntry $entry): int
    {
        return (int) $line->getOperationDate()->diff(\DateTimeImmutable::createFromInterface($entry->getDate()))->days;
    }

    /** Cuenta, importe y día, para los grupos del mismo día. */
    private static function dayKey(?int $accountId, int $cents, \DateTimeInterface $day): string
    {
        return self::key($accountId, $cents).'|'.$day->format('Y-m-d');
    }

    /** Cuenta e importe, para agrupar. */
    private static function key(?int $accountId, int $cents): string
    {
        return $accountId.'|'.$cents;
    }
}
