<?php

namespace App\Service\Accounting\Remittance;

/**
 * Los días en que se mueven los adeudos SEPA: de lunes a viernes, menos los cierres
 * de TARGET2 (1 de enero, Viernes Santo, Lunes de Pascua, 1 de mayo, 25 y 26 de
 * diciembre). Los festivos nacionales o locales no cuentan: el sistema de pagos abre.
 *
 * Una fecha de cargo que cae en día no hábil se cobra el siguiente hábil, y el margen
 * para dar una remesa por no cobrada se cuenta en días hábiles: un fin de semana o la
 * Semana Santa no son «días sin abono».
 *
 * Todo es puro y sin estado: sólo fechas.
 */
final class BankBusinessDays
{
    /** Los cierres de fecha fija, «mes-día». */
    private const FIXED_CLOSINGS = ['01-01', '05-01', '12-25', '12-26'];

    private function __construct()
    {
    }

    /**
     * Si ese día el banco mueve adeudos.
     *
     * @param \DateTimeImmutable $day Cualquier hora de ese día.
     */
    public static function isBusinessDay(\DateTimeImmutable $day): bool
    {
        if ((int) $day->format('N') >= 6 || \in_array($day->format('m-d'), self::FIXED_CLOSINGS, true)) {
            return false;
        }
        $easter = self::easterSunday((int) $day->format('Y'));

        return !\in_array($day->format('Y-m-d'), [
            $easter->modify('-2 days')->format('Y-m-d'),
            $easter->modify('+1 day')->format('Y-m-d'),
        ], true);
    }

    /**
     * El mismo día si es hábil; si no, el siguiente que lo sea. Es el día en que de
     * verdad se cobra una remesa pedida para esa fecha.
     *
     * @return \DateTimeImmutable A medianoche.
     */
    public static function nextOrSame(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $day = $day->setTime(0, 0);
        while (!self::isBusinessDay($day)) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }

    /**
     * El día que queda a tantos días hábiles: hacia delante si es positivo, hacia
     * atrás si es negativo. Con cero, el mismo día aunque no sea hábil.
     *
     * @return \DateTimeImmutable A medianoche.
     */
    public static function add(\DateTimeImmutable $day, int $businessDays): \DateTimeImmutable
    {
        $day = $day->setTime(0, 0);
        $step = $businessDays >= 0 ? '+1 day' : '-1 day';
        for ($left = abs($businessDays); $left > 0;) {
            $day = $day->modify($step);
            if (self::isBusinessDay($day)) {
                --$left;
            }
        }

        return $day;
    }

    /**
     * El domingo de Pascua de un año (calendario gregoriano, algoritmo anónimo de
     * Meeus/Jones/Butcher). No se usa `easter_date()` porque la extensión `calendar`
     * de PHP no está garantizada en el hosting.
     */
    public static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOfMonth = ($h + $l - 7 * $m + 114) % 31 + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $dayOfMonth));
    }
}
