<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\BankStatement;

/**
 * Lo que dejó una subida de extracto: sus extractos (uno por cuenta del fichero) y
 * cuántos movimientos eran nuevos, cuántos ya estaban y cuántos se ligaron solos.
 */
final class BankStatementImport
{
    /**
     * @param list<BankStatement> $statements
     * @param list<string>        $warnings   Avisos del fichero que no impidieron leerlo.
     */
    public function __construct(
        public readonly array $statements,
        public readonly int $added,
        public readonly int $alreadyThere,
        public readonly int $linked,
        public readonly array $warnings,
    ) {
    }
}
