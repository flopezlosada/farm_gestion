<?php

namespace App\Service\Accounting\CashCount;

/**
 * El saldo de una cuenta un día concreto, a las dos horas que importan en un arqueo.
 *
 * Los apuntes sólo llevan fecha, no hora: si se contó la caja el mismo día en que hay
 * apuntes, no se sabe si se contó antes o después de ellos. Por eso un recuento cuadra
 * si coincide con cualquiera de los dos saldos ({@see matches()}).
 */
final class DayBalances
{
    /**
     * @param int $openingCents Al empezar el día: apertura más los apuntes de días anteriores.
     * @param int $closingCents Al cerrarlo: contando también los apuntes de ese día.
     */
    public function __construct(
        public readonly int $openingCents,
        public readonly int $closingCents,
    ) {
    }

    /** Si lo contado coincide al céntimo con el saldo antes o después de los apuntes del día. */
    public function matches(int $countedCents): bool
    {
        return $countedCents === $this->openingCents || $countedCents === $this->closingCents;
    }

    /** Si los apuntes de ese día mueven el saldo: entonces hay dos saldos que enseñar. */
    public function changesThatDay(): bool
    {
        return $this->openingCents !== $this->closingCents;
    }

    /**
     * El saldo con el que se compara lo contado: el que coincide, si alguno coincide;
     * si no, el del cierre del día.
     */
    public function comparedWith(int $countedCents): int
    {
        return $countedCents === $this->openingCents && $this->changesThatDay() ? $this->openingCents : $this->closingCents;
    }

    /** Céntimos → «1.234,56 €» (o «-12,40 €»), como se le escribe a una persona. */
    public static function euros(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2, ',', '.') . ' €';
    }
}
