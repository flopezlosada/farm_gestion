<?php

namespace App\Service\Accounting\Norma43;

/**
 * Un extracto Norma 43 leído y validado: una o varias cuentas, y los avisos que no
 * impiden aceptarlo (por ejemplo, que falte el registro de fin).
 */
final class Norma43File
{
    /**
     * @param list<Norma43Account> $accounts
     * @param list<string>         $warnings
     */
    public function __construct(
        public readonly array $accounts,
        public readonly array $warnings,
    ) {
    }
}
