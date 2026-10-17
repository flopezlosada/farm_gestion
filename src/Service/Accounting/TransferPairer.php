<?php

namespace App\Service\Accounting;

use App\Entity\AccountEntry;

/**
 * Casa las dos mitades de los traspasos que llegan sueltas, como las del Excel del
 * libro: allí un traspaso son dos filas sin nada que las una.
 *
 * Una salida casa con una entrada si son de cuentas distintas, el importe es el mismo
 * con el signo contrario y las fechas distan como mucho {@see self::MAX_DAYS_APART}
 * días (el banco abona al día siguiente). Gana la más cercana en fecha. Lo dudoso NO
 * se casa: si dos entradas empatan como la mejor, o los importes no coinciden (en el
 * libro de 2026 hay cajas que sacan 100 € y meten 115 €), la mitad se queda suelta y
 * la ve quien revisa los traspasos sin pareja. Casar mal es peor que no casar.
 */
class TransferPairer
{
    public const MAX_DAYS_APART = 2;

    /**
     * Enlaza las mitades que casan y devuelve las que se quedan sin pareja. Sólo mira
     * traspasos todavía sin enlazar; el resto de apuntes los ignora.
     *
     * @param AccountEntry[] $entries
     *
     * @return AccountEntry[] los traspasos que siguen sin pareja
     */
    public function pair(array $entries): array
    {
        $loose = array_values(array_filter(
            $entries,
            static fn (AccountEntry $e): bool => $e->isLooseTransfer(),
        ));

        $outgoing = array_filter($loose, static fn (AccountEntry $e): bool => self::cents($e) < 0);
        $incoming = array_filter($loose, static fn (AccountEntry $e): bool => self::cents($e) > 0);
        usort($outgoing, static fn (AccountEntry $a, AccountEntry $b): int => $a->getDate() <=> $b->getDate());

        foreach ($outgoing as $out) {
            $match = $this->bestMatch($out, $incoming);
            if ($match !== null) {
                $out->pairWith($incoming[$match]);
                unset($incoming[$match]);
            }
        }

        return array_values(array_filter(
            $loose,
            static fn (AccountEntry $e): bool => $e->getTransferPeer() === null,
        ));
    }

    /**
     * Clave de la entrada que mejor casa con la salida, o null si ninguna casa o si
     * hay empate en la más cercana.
     *
     * @param array<int, AccountEntry> $incoming
     */
    private function bestMatch(AccountEntry $out, array $incoming): ?int
    {
        $distances = [];
        foreach ($incoming as $key => $in) {
            if (!TransferRecorder::canPair($out, $in)) {
                continue;
            }
            $days = self::daysApart($out, $in);
            if ($days <= self::MAX_DAYS_APART) {
                $distances[$key] = $days;
            }
        }
        if ($distances === []) {
            return null;
        }

        $closest = min($distances);
        $best = array_keys($distances, $closest, true);

        return \count($best) === 1 ? $best[0] : null;
    }

    /** Importe en céntimos, para comparar sin los errores del float. */
    private static function cents(AccountEntry $entry): int
    {
        return (int) round((float) $entry->getAmount() * 100);
    }

    /** Días naturales entre las fechas de dos apuntes, sin signo. */
    private static function daysApart(AccountEntry $a, AccountEntry $b): int
    {
        $day = static fn (AccountEntry $e): \DateTimeImmutable => new \DateTimeImmutable($e->getDate()->format('Y-m-d'));

        return $day($a)->diff($day($b))->days;
    }
}
