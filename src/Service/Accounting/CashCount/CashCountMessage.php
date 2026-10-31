<?php

namespace App\Service\Accounting\CashCount;

/**
 * Un arqueo tal como se escribe al bot: «caja huerta 400,56», con la fecha opcional
 * detrás («caja huerta 400,56 30/10»). Sin fecha, es de hoy.
 *
 * Es una orden con formato fijo y se lee sin IA: el importe de una caja no se adivina.
 * Lo que no está claro se rechaza diciendo cómo escribirlo, en vez de suponer:
 * - Importe: «400,56», «400.56», «400», «400,5», «1.234,56», «400,56 €» o «0». Un punto
 *   o una coma seguidos de tres cifras («1,234», «400.560») pueden ser miles o
 *   decimales, y se pide con coma decimal. Negativo no puede ser: es lo que hay en la caja.
 * - Fecha: «30/10», «30/10/26» o «30/10/2026». Sin año, la más reciente que no sea
 *   futura (el 2 de enero, «31/12» es del año anterior). Con año, futura no vale.
 */
final class CashCountMessage
{
    /**
     * La forma de un arqueo: «caja», el nombre, el importe, quizá «€» y quizá la fecha.
     * El nombre es lo más corto posible, para que el importe sea el último número.
     */
    private const SHAPE = '~^caja\s+(?<name>\S.*?)\s+(?<amount>-?\d[\d.,]*)\s*(?:€|eur|euros)?(?:\s+(?<date>\d{1,2}/\d{1,2}(?:/\d{2}(?:\d{2})?)?))?$~iu';

    private const HOW_TO_WRITE_AMOUNT = 'Escríbelo con coma decimal y sin puntos de miles: «caja huerta 1234,56».';

    /**
     * @param string             $accountName  El nombre de la caja, tal como se escribió.
     * @param int                $countedCents Lo contado, en céntimos.
     * @param \DateTimeImmutable $date         Día del recuento.
     */
    private function __construct(
        public readonly string $accountName,
        public readonly int $countedCents,
        public readonly \DateTimeImmutable $date,
    ) {
    }

    /**
     * Si el texto tiene forma de arqueo. No dice si es válido: «caja huerta -20» tiene
     * la forma, y {@see parse()} lo rechaza explicando por qué.
     *
     * @param string $text Lo escrito.
     */
    public static function looksLikeOne(string $text): bool
    {
        return preg_match(self::SHAPE, self::squash($text)) === 1;
    }

    /**
     * Lee un arqueo.
     *
     * @param string             $text  Lo escrito.
     * @param \DateTimeImmutable $today Hoy, para la fecha por defecto y para no admitir fechas futuras.
     *
     * @throws CashCountRejected Si no tiene forma de arqueo, o el importe o la fecha no están claros.
     */
    public static function parse(string $text, \DateTimeImmutable $today): self
    {
        if (preg_match(self::SHAPE, self::squash($text), $m) !== 1) {
            throw new CashCountRejected('No lo entiendo como un arqueo. Escríbelo así: «caja huerta 400,56», y si no es de hoy, con la fecha detrás: «caja huerta 400,56 30/10».');
        }
        $today = $today->setTime(0, 0);

        return new self(
            trim($m['name']),
            self::amountCents($m['amount']),
            isset($m['date']) && $m['date'] !== '' ? self::date($m['date'], $today) : $today,
        );
    }

    /**
     * El importe en céntimos, sin pasar por decimales flotantes.
     *
     * @param string $raw El importe escrito, sin el «€».
     *
     * @throws CashCountRejected Si es negativo o no está claro.
     */
    public static function amountCents(string $raw): int
    {
        if (str_starts_with($raw, '-')) {
            throw new CashCountRejected('Lo contado no puede ser negativo: es el dinero que hay en la caja. Si está vacía, escribe 0.');
        }
        $patterns = [
            '~^(?<euros>\d+)$~',
            '~^(?<euros>\d+)[.,](?<cents>\d{1,2})$~',
            '~^(?<euros>\d{1,3}(?:\.\d{3})+),(?<cents>\d{1,2})$~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $m) === 1) {
                return (int) str_replace('.', '', $m['euros']) * 100 + (int) str_pad($m['cents'] ?? '0', 2, '0');
            }
        }

        throw new CashCountRejected(sprintf('No sé si «%s» son euros con decimales o miles. %s', $raw, self::HOW_TO_WRITE_AMOUNT));
    }

    /**
     * El día del recuento.
     *
     * @param string             $raw   «30/10», «30/10/26» o «30/10/2026».
     * @param \DateTimeImmutable $today Hoy, a medianoche.
     *
     * @throws CashCountRejected Si no existe o es futura.
     */
    private static function date(string $raw, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $parts = array_map('intval', explode('/', $raw));
        [$day, $month] = $parts;
        $explicitYear = isset($parts[2]);
        $year = $explicitYear ? ($parts[2] < 100 ? 2000 + $parts[2] : $parts[2]) : (int) $today->format('Y');

        $date = self::day($year, $month, $day);
        if (!$explicitYear && ($date === null || $date > $today)) {
            $date = self::day($year - 1, $month, $day) ?? $date;
        }
        if ($date === null) {
            throw new CashCountRejected(sprintf('El %s no existe. Escribe la fecha así: 30/10.', $raw));
        }
        if ($date > $today) {
            throw new CashCountRejected(sprintf('El %s todavía no ha llegado: un arqueo es de lo que había ese día.', $raw));
        }

        return $date;
    }

    /** Ese día a medianoche, o null si no existe (un 29 de febrero de un año que no es bisiesto). */
    private static function day(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        return checkdate($month, $day, $year) ? new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)) : null;
    }

    /** Sin espacios de más (los de Telegram pueden ser de no separación). */
    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
