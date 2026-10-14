<?php

namespace App\Service\Accounting\Remittance;

use App\Service\Accounting\MonthNames;

/**
 * Pasa al mes nuevo los textos de una remesa copiada de la anterior: el nombre
 * («Recibos AGOSTO 2026» → «Recibos SEPTIEMBRE 2026») y la referencia de cada adeudo,
 * que el banco quiere distinta en cada recibo.
 *
 * Reconoce el mes por su nombre en castellano (respetando si iba en mayúsculas), las
 * formas numéricas habituales (202608, 2026-08, 08/2026, 08-2026, 08.2026) y el año
 * suelto. Lo que no reconoce lo deja como estaba: es mejor una referencia repetida,
 * que la remesa detecta antes de enviarse, que una inventada.
 */
final class RemittancePeriodText
{
    /**
     * @param string             $text El texto del periodo anterior.
     * @param \DateTimeInterface $from Cualquier día del periodo anterior.
     * @param \DateTimeInterface $to   Cualquier día del periodo nuevo.
     */
    public static function rewrite(string $text, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        [$fromYear, $fromMonth] = [(int) $from->format('Y'), (int) $from->format('n')];
        [$toYear, $toMonth] = [(int) $to->format('Y'), (int) $to->format('n')];
        if ($fromYear === $toYear && $fromMonth === $toMonth) {
            return $text;
        }

        $mm = static fn (int $m): string => sprintf('%02d', $m);
        $numeric = [
            '/\b' . $fromYear . $mm($fromMonth) . '\b/' => $toYear . $mm($toMonth),
            '/\b' . $fromYear . '-' . $mm($fromMonth) . '\b/' => $toYear . '-' . $mm($toMonth),
            '/\b' . $mm($fromMonth) . '([\/.-])' . $fromYear . '\b/' => $mm($toMonth) . '${1}' . $toYear,
        ];
        $text = (string) preg_replace(array_keys($numeric), array_values($numeric), $text);

        $text = (string) preg_replace_callback(
            '/\b' . MonthNames::long($fromMonth) . '\b/iu',
            static fn (array $m): string => self::sameCase($m[0], MonthNames::long($toMonth)),
            $text,
        );

        return $fromYear === $toYear ? $text : (string) preg_replace('/\b' . $fromYear . '\b/', (string) $toYear, $text);
    }

    /**
     * El mes que nombra un texto como «08. Recibos AGOSTO 2026.xlsx», para proponerlo
     * al importar. Null si no lleva nombre de mes y año.
     */
    public static function guessPeriod(string $text): ?\DateTimeImmutable
    {
        foreach (MonthNames::LONG as $month => $name) {
            if (preg_match('/\b' . $name . '\b\D{0,5}(\d{4})\b/iu', $text, $m) === 1) {
                return new \DateTimeImmutable(sprintf('%s-%02d-01', $m[1], $month));
            }
        }

        return null;
    }

    /** `$word` escrito como estaba escrito `$like`: todo mayúsculas, todo minúsculas o con inicial. */
    private static function sameCase(string $like, string $word): string
    {
        return match (true) {
            $like === mb_strtoupper($like) => mb_strtoupper($word),
            $like === mb_strtolower($like) => mb_strtolower($word),
            default => $word,
        };
    }
}
