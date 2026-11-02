<?php

namespace App\Service\Accounting\Suggestion;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Repository\AccountEntryRepository;

/**
 * La partida de un movimiento del banco, sacada del propio libro: la que llevaban
 * las últimas veces que el banco dijo lo mismo en esa cuenta.
 *
 * Sin IA y sin reglas que mantener (decidido: los conceptos llevan nombres de
 * socixs). Casi la mitad de los movimientos repiten concepto con la misma partida
 * (la gestoría, el teléfono, la Seguridad Social, el préstamo); para ésos se propone,
 * y quien anota sólo confirma. Si las últimas veces no coinciden, no se propone nada:
 * mejor nada que una partida equivocada que se acepta sin mirar.
 *
 * El concepto se compara sin cifras, tildes, espacios ni signos: «rcbo.caymuz sl»
 * y «rcbo.caymuz, s.l.» son lo mismo; un número de recibo o de referencia que
 * cambia cada mes no lo separa.
 */
class CategorySuggester
{
    /** Cuántas de las últimas veces tienen que coincidir (o todas, si son menos). */
    public const SAMPLE = 5;

    /** Hasta cuándo hacia atrás se mira el libro. */
    private const HISTORY = '-18 months';

    public function __construct(private readonly AccountEntryRepository $entries)
    {
    }

    /**
     * La propuesta de cada movimiento, con una sola consulta para todos.
     *
     * @param list<BankStatementLine> $lines Los movimientos.
     *
     * @return array<int, CategorySuggestion> Por id del movimiento; sin entrada, nada que proponer.
     */
    public function forLines(array $lines): array
    {
        if ($lines === []) {
            return [];
        }
        $accounts = [];
        $earliest = null;
        foreach ($lines as $line) {
            $accounts[$line->getAccount()->getId()] = $line->getAccount();
            $earliest = $earliest === null || $line->getOperationDate() < $earliest ? $line->getOperationDate() : $earliest;
        }
        $history = $this->entries->findCategorizedSince(array_values($accounts), $earliest->modify(self::HISTORY));

        $suggestions = [];
        foreach ($lines as $line) {
            $suggestion = self::suggest($line, $history);
            if ($suggestion !== null) {
                $suggestions[(int) $line->getId()] = $suggestion;
            }
        }

        return $suggestions;
    }

    /** La propuesta de un movimiento, o null. */
    public function forLine(BankStatementLine $line): ?CategorySuggestion
    {
        return $this->forLines([$line])[(int) $line->getId()] ?? null;
    }

    /**
     * La partida de las últimas veces que ese concepto entró en esa cuenta, en el mismo
     * sentido (cobro o pago) y antes del movimiento, si coinciden todas. Puro.
     *
     * @param list<AccountEntry> $history Apuntes con partida, los más recientes primero.
     */
    public static function suggest(BankStatementLine $line, array $history): ?CategorySuggestion
    {
        $key = self::key($line->getSuggestedConcept());
        if ($key === '') {
            return null;
        }
        $income = $line->getAmountCents() > 0;
        $same = array_values(array_filter($history, static fn (AccountEntry $e): bool => $e->getAccount() === $line->getAccount()
            && $e->getDate() !== null && $e->getDate() <= $line->getOperationDate()
            && ((float) $e->getAmount() > 0) === $income
            && self::key($e->getConcept()) === $key));
        $recent = \array_slice($same, 0, self::SAMPLE);
        if ($recent === []) {
            return null;
        }
        $categories = array_unique(array_map(static fn (AccountEntry $e): int => (int) $e->getCategory()?->getId(), $recent));
        $category = $recent[0]->getCategory();
        if (\count($categories) !== 1 || $category === null || !$category->isActive()) {
            return null;
        }

        return new CategorySuggestion($category, \count($recent));
    }

    /**
     * El concepto sin lo que cambia de un mes a otro ni las erratas de puntuación: sólo
     * letras, en minúscula y sin tildes.
     */
    public static function key(string $concept): string
    {
        $plain = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $concept);

        return preg_replace('/[^a-z]+/', '', $plain === false ? mb_strtolower($concept) : $plain) ?? '';
    }
}
