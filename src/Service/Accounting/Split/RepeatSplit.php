<?php

namespace App\Service\Accounting\Split;

use App\Entity\AccountEntry;
use App\Entity\IssuedInvoice;
use App\Repository\AccountEntryRepository;
use App\Service\Accounting\ConceptKey;

/**
 * Partir un cargo que se repite como se partió la última vez. El caso que lo pide: el
 * Ayuntamiento cobra cada mes 158,95 € que son 108,95 de alquiler (con factura) y 50
 * de suministros (sin ella); partirlo a mano cada mes es lo que no se quiere.
 *
 * Es la regla de reparto sin pantalla de reglas: la regla es el último apunte partido
 * de la misma cuenta, con el mismo concepto (comparado por {@see ConceptKey}) y el
 * mismo importe total antes de partir. Si el importe cambia, deja de proponerse y se
 * parte a mano una vez; la siguiente ya sale sola. No se aplica sin que alguien lo
 * confirme.
 */
class RepeatSplit
{
    /** Hasta cuánto atrás se busca el último partido: un cargo trimestral cabe. */
    private const LOOKBACK = '-120 days';

    public function __construct(private readonly AccountEntryRepository $entries)
    {
    }

    /**
     * Cómo partir este apunte, sacado del último que se partió igual: el apunte de
     * entonces y sus partes. Null si no hay ninguno, o si éste ya está partido o es
     * una parte, o es un traspaso.
     *
     * @return array{previous: AccountEntry, parts: list<AccountEntry>}|null
     */
    public function templateFor(AccountEntry $entry): ?array
    {
        if ($entry->getId() === null || $entry->getAccount() === null || $entry->getDate() === null
            || $entry->isTransfer() || $entry->getSplitFrom() !== null
            || $this->entries->count(['splitFrom' => $entry]) > 0) {
            return null;
        }
        $date = \DateTimeImmutable::createFromInterface($entry->getDate());
        $previous = $this->entries->findSplitBetween($entry->getAccount(), $date->modify(self::LOOKBACK), $date);

        return self::pick($entry, $previous, $this->entries->findPartsOf($previous));
    }

    /**
     * De los apuntes partidos de antes (los más recientes primero), el primero con el
     * mismo concepto y el mismo total antes de partir. Puro.
     *
     * @param list<AccountEntry> $previous Partidos de la misma cuenta, más recientes primero.
     * @param list<AccountEntry> $parts    Sus partes.
     *
     * @return array{previous: AccountEntry, parts: list<AccountEntry>}|null
     */
    public static function pick(AccountEntry $entry, array $previous, array $parts): ?array
    {
        $key = ConceptKey::of($entry->getConcept());
        if ($key === '') {
            return null;
        }
        $wanted = IssuedInvoice::toCents($entry->getAmount());
        foreach ($previous as $candidate) {
            $own = array_values(array_filter($parts, static fn (AccountEntry $p): bool => $p->getSplitFrom() === $candidate));
            $total = IssuedInvoice::toCents($candidate->getAmount())
                + array_sum(array_map(static fn (AccountEntry $p): int => IssuedInvoice::toCents($p->getAmount()), $own));
            if ($own !== [] && $total === $wanted && ConceptKey::of($candidate->getConcept()) === $key) {
                return ['previous' => $candidate, 'parts' => $own];
            }
        }

        return null;
    }

    /**
     * Parte el apunte como se partió el de entonces: una parte nueva por cada una de
     * aquéllas, con su importe, partida y concepto. Sin guardar.
     *
     * @param list<AccountEntry> $parts Las partes de la vez anterior.
     *
     * @return list<AccountEntry> Las partes nuevas, para persistirlas.
     *
     * @throws \InvalidArgumentException Si alguna no cabe ({@see AccountEntry::splitOff()}).
     */
    public static function apply(AccountEntry $entry, array $parts): array
    {
        return array_map(
            static fn (AccountEntry $p): AccountEntry => $entry->splitOff(
                IssuedInvoice::fromCents(abs(IssuedInvoice::toCents($p->getAmount()))),
                $p->getCategory(),
                $p->getConcept(),
            ),
            $parts,
        );
    }
}
