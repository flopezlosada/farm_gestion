<?php

namespace App\Service\Accounting\Suggestion;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\Provider;
use App\Repository\AccountEntryRepository;
use App\Service\Accounting\ConceptKey;

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
 * El concepto se compara con {@see ConceptKey}: sin tildes, espacios, signos ni
 * cifras, salvo los números sueltos de tres (el modelo 111 o 303 de Hacienda).
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
        $history = $this->historyFor($lines);
        $suggestions = [];
        foreach ($lines as $line) {
            $suggestion = self::suggest($line, $history);
            if ($suggestion !== null) {
                $suggestions[(int) $line->getId()] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * El proveedor de cada pago, con la misma regla que {@see providerFor()} y una sola
     * consulta para todos.
     *
     * @param list<BankStatementLine> $lines Los movimientos.
     *
     * @return array<int, Provider> Por id del movimiento; sin entrada, nada que proponer.
     */
    public function providersFor(array $lines): array
    {
        $payments = array_values(array_filter($lines, static fn (BankStatementLine $l): bool => $l->getAmountCents() < 0));
        $history = $this->historyFor($payments);
        $providers = [];
        foreach ($payments as $line) {
            $provider = self::suggestProvider($line, $history);
            if ($provider !== null) {
                $providers[(int) $line->getId()] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Los apuntes con partida de las cuentas de estos movimientos, desde
     * {@see HISTORY} antes del más antiguo, en una consulta.
     *
     * @param list<BankStatementLine> $lines
     *
     * @return list<AccountEntry> Los más recientes primero.
     */
    private function historyFor(array $lines): array
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

        return $this->entries->findCategorizedSince(array_values($accounts), $earliest->modify(self::HISTORY));
    }

    /** La propuesta de un movimiento, o null. */
    public function forLine(BankStatementLine $line): ?CategorySuggestion
    {
        return $this->forLines([$line])[(int) $line->getId()] ?? null;
    }

    /**
     * El proveedor de un pago del banco, con la misma regla que la partida: el de las
     * últimas veces que el banco dijo lo mismo, si todas lo llevaban. Así lo que se casó
     * una vez con su ficha se sigue casando solo.
     */
    public function providerFor(BankStatementLine $line): ?Provider
    {
        if ($line->getAmountCents() >= 0) {
            return null;
        }

        return self::suggestProvider($line, $this->entries->findCategorizedSince([$line->getAccount()], $line->getOperationDate()->modify(self::HISTORY)));
    }

    /**
     * La partida de las últimas veces que ese concepto entró en esa cuenta, en el mismo
     * sentido (cobro o pago) y antes del movimiento, si coinciden todas. Puro.
     *
     * @param list<AccountEntry> $history Apuntes con partida, los más recientes primero.
     */
    public static function suggest(BankStatementLine $line, array $history): ?CategorySuggestion
    {
        $recent = self::recent($line, $history);
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
     * El proveedor de las últimas veces que ese concepto salió de esa cuenta, si todas
     * llevaban el mismo. Si alguna no tenía ficha o tenía otra, nada. Puro.
     *
     * @param list<AccountEntry> $history Apuntes, los más recientes primero.
     */
    public static function suggestProvider(BankStatementLine $line, array $history): ?Provider
    {
        $recent = self::recent($line, $history);
        $providers = array_unique(array_map(static fn (AccountEntry $e): int => (int) $e->getProvider()?->getId(), $recent));

        return $recent !== [] && \count($providers) === 1 && $providers[0] !== 0 ? $recent[0]->getProvider() : null;
    }

    /**
     * Las últimas {@see SAMPLE} veces que el banco dijo lo mismo en esa cuenta, en el
     * mismo sentido (cobro o pago) y antes del movimiento.
     *
     * @param list<AccountEntry> $history Apuntes, los más recientes primero.
     *
     * @return list<AccountEntry>
     */
    private static function recent(BankStatementLine $line, array $history): array
    {
        $key = ConceptKey::of($line->getSuggestedConcept());
        if ($key === '') {
            return [];
        }
        $income = $line->getAmountCents() > 0;

        return \array_slice(array_values(array_filter($history, static fn (AccountEntry $e): bool => $e->getAccount() === $line->getAccount()
            && $e->getDate() !== null && $e->getDate() <= $line->getOperationDate()
            && ((float) $e->getAmount() > 0) === $income
            && ConceptKey::of($e->getConcept()) === $key)), 0, self::SAMPLE);
    }
}
