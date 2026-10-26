<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Partner;

/**
 * Busca la ficha de quien paga un recibo cuando el nombre del Excel no casa exacto
 * con ninguna, y dice POR QUÉ cree que es esa: es lo que necesita quien revisa para
 * decidir sin abrir dos pantallas.
 *
 * Los casos que se ven en las remesas reales:
 * - errata de una letra («Fernándes» / «Fernández»);
 * - apellido cortado por el banco («ITURRI» / «ITURRIZ»);
 * - a la ficha le falta un nombre o un apellido («Eva» / «Eva María»);
 * - nombre y apellidos partidos de otra forma (la ficha tiene el segundo nombre
 *   en los apellidos).
 *
 * Una ficha es candidata cuando TODAS sus palabras están en el recibo (igual, con una
 * errata o cortadas) y al recibo le sobran como mucho dos. Las partículas («de»,
 * «la»…) no cuentan para casar.
 *
 * Además propone cómo quedaría el nombre de la ficha cuando lo que falla es la ficha
 * (le faltan palabras o están mal partidas). Con una errata o un corte no: no se sabe
 * cuál de los dos está bien, y el banco corta los nombres largos.
 */
final class PartnerNameMatcher
{
    /** Palabras que no distinguen a nadie: se saltan al casar y van con su apellido. */
    private const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'i', 'san', 'da', 'dos'];

    /** Lo más que le puede sobrar al recibo para seguir siendo la misma persona. */
    private const MAX_EXTRA = 2;

    /**
     * Las fichas que pueden ser de quien paga, la más probable primero.
     *
     * @param list<Partner> $partners
     *
     * @return list<array{partner: Partner, reasons: list<string>, rename: array{name: string, surname: string}|null, score: int}>
     */
    public static function candidates(string $debtorName, array $partners): array
    {
        $receipt = self::words($debtorName);
        if (\count(self::significant($receipt)) < 2) {
            return [];
        }

        $found = [];
        foreach ($partners as $partner) {
            $match = self::match($receipt, $partner);
            if ($match !== null) {
                $found[] = $match;
            }
        }
        usort($found, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $found;
    }

    /**
     * Cómo casa un recibo con una ficha, o null si no es ella.
     *
     * @param list<array{raw: string, key: string}> $receipt
     *
     * @return array{partner: Partner, reasons: list<string>, rename: array{name: string, surname: string}|null, score: int}|null
     */
    private static function match(array $receipt, Partner $partner): ?array
    {
        $first = self::words((string) $partner->getName());
        $last = self::words((string) $partner->getSurname());
        $mine = self::significant([...$first, ...$last]);
        if (\count($mine) < 2) {
            return null;
        }

        $used = [];
        $reasons = [];
        $score = 0;
        $exact = 0;
        // Para cada palabra de la ficha, la del recibo que le corresponde: mismo
        // orden de preferencia que una persona (igual, cortada, con una errata).
        $pairs = [];
        foreach ($mine as $word) {
            $best = null;
            foreach ($receipt as $i => $theirs) {
                if (isset($used[$i]) || self::isParticle($theirs['key'])) {
                    continue;
                }
                $kind = self::compare($word['key'], $theirs['key']);
                if ($kind !== null && ($best === null || self::rank($kind) > self::rank($best['kind']))) {
                    $best = ['i' => $i, 'kind' => $kind];
                }
            }
            if ($best === null) {
                return null;
            }
            $used[$best['i']] = true;
            $pairs[$best['i']] = $word['raw'];
            $theirs = $receipt[$best['i']]['raw'];
            if ($best['kind'] === 'same') {
                ++$exact;
            } else {
                $reasons[] = sprintf(
                    $best['kind'] === 'cut' ? '«%s» en el recibo y «%s» en la ficha: uno de los dos está cortado' : '«%s» en el recibo y «%s» en la ficha: una letra distinta',
                    $theirs, $word['raw'],
                );
            }
            $score += self::rank($best['kind']);
        }
        if ($exact === 0) {
            return null;
        }

        $extra = array_values(array_filter(
            $receipt,
            static fn (array $w, int $i): bool => !isset($used[$i]) && !self::isParticle($w['key']),
            ARRAY_FILTER_USE_BOTH,
        ));
        if (\count($extra) > self::MAX_EXTRA) {
            return null;
        }
        foreach ($extra as $word) {
            $reasons[] = sprintf('a la ficha le falta «%s»', $word['raw']);
            --$score;
        }

        $rename = self::rename($receipt, $pairs, $first, $partner);
        if ($rename !== null && $extra === []) {
            $reasons[] = 'en la ficha el nombre y los apellidos están partidos de otra forma';
        }
        if ($reasons === []) {
            $reasons[] = 'el mismo nombre escrito en otro orden';
        }

        return ['partner' => $partner, 'reasons' => $reasons, 'rename' => $rename, 'score' => $score];
    }

    /**
     * Cómo quedaría el nombre de la ficha con lo que dice el recibo, o null si la ficha
     * ya lo tiene así. Las palabras que ya estaban se escriben como en la ficha (si el
     * banco corta un apellido, la ficha lo tiene entero); las nuevas, como en el recibo.
     *
     * Apellidos: los dos últimos (o los dos primeros si el recibo empieza por ellos),
     * cada uno con sus partículas delante («De La Cal»).
     *
     * @param list<array{raw: string, key: string}> $receipt
     * @param array<int, string>                    $pairs   posición en el recibo => palabra de la ficha
     * @param list<array{raw: string, key: string}> $first   el nombre de la ficha
     *
     * @return array{name: string, surname: string}|null
     */
    private static function rename(array $receipt, array $pairs, array $first, Partner $partner): ?array
    {
        $words = [];
        foreach ($receipt as $i => $word) {
            $words[] = $pairs[$i] ?? self::likeFicha($word['raw'], $partner);
        }
        if (\count($words) < 3) {
            return null;
        }

        // ¿El recibo empieza por el nombre o por los apellidos? Lo dice dónde cae la
        // primera palabra del nombre de la ficha.
        $firstKey = self::significant($first)[0]['key'] ?? null;
        $at = null;
        foreach ($receipt as $i => $word) {
            if (isset($pairs[$i]) && self::words($pairs[$i])[0]['key'] === $firstKey) {
                $at = $i;
                break;
            }
        }
        $nameFirst = $at === null || $at < \count($receipt) / 2;

        $units = self::surnameUnits($words, $nameFirst);
        if ($units === null) {
            return null;
        }
        [$name, $surname] = $units;
        $proposal = ['name' => implode(' ', $name), 'surname' => implode(' ', $surname)];
        $current = ['name' => trim((string) $partner->getName()), 'surname' => trim((string) $partner->getSurname())];

        return self::same($proposal, $current) ? null : $proposal;
    }

    /**
     * Separa nombre y apellidos: dos apellidos, cada uno con las partículas que lleva
     * delante («De La Cal»). Al final del recibo si empieza por el nombre; al
     * principio si empieza por los apellidos.
     *
     * @param list<string> $words En el orden del recibo.
     *
     * @return array{0: list<string>, 1: list<string>}|null
     */
    private static function surnameUnits(array $words, bool $nameFirst): ?array
    {
        $count = \count($words);
        if ($nameFirst) {
            $i = $count - 1;
            $units = 0;
            while ($units < 2 && $i > 0) {
                --$i;
                ++$units;
                while ($i >= 0 && self::isParticle(self::key($words[$i]))) {
                    --$i;
                }
            }
            $name = \array_slice($words, 0, $i + 1);
            $surname = \array_slice($words, $i + 1);
        } else {
            $i = 0;
            $units = 0;
            while ($units < 2 && $i < $count - 1) {
                if (!self::isParticle(self::key($words[$i]))) {
                    ++$units;
                }
                ++$i;
            }
            $surname = \array_slice($words, 0, $i);
            $name = \array_slice($words, $i);
        }

        return $name === [] || $surname === [] ? null : [$name, $surname];
    }

    /** Qué tienen que ver dos palabras: 'same', 'cut', 'typo' o null. */
    private static function compare(string $mine, string $theirs): ?string
    {
        if ($mine === $theirs) {
            return 'same';
        }
        $short = min(\strlen($mine), \strlen($theirs));
        if ($short >= 4 && (str_starts_with($mine, $theirs) || str_starts_with($theirs, $mine))) {
            return 'cut';
        }
        $allowed = $short >= 8 ? 2 : ($short >= 4 ? 1 : 0);

        return $allowed > 0 && levenshtein($mine, $theirs) <= $allowed ? 'typo' : null;
    }

    private static function rank(string $kind): int
    {
        return ['same' => 3, 'cut' => 2, 'typo' => 2][$kind];
    }

    /**
     * Las palabras de un nombre, cada una tal cual y en minúsculas sin tildes.
     *
     * @return list<array{raw: string, key: string}>
     */
    private static function words(string $name): array
    {
        $raw = preg_split('/[\s,]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $w): array => ['raw' => $w, 'key' => self::key($w)], $raw),
            static fn (array $w): bool => $w['key'] !== '',
        ));
    }

    /**
     * @param list<array{raw: string, key: string}> $words
     *
     * @return list<array{raw: string, key: string}>
     */
    private static function significant(array $words): array
    {
        return array_values(array_filter($words, static fn (array $w): bool => !self::isParticle($w['key'])));
    }

    private static function key(string $word): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $word));
    }

    private static function isParticle(string $key): bool
    {
        return \in_array($key, self::PARTICLES, true);
    }

    /** Una palabra nueva escrita como escribe la ficha: todo mayúsculas si la ficha lo está. */
    private static function likeFicha(string $word, Partner $partner): string
    {
        $ficha = trim($partner->getName() . ' ' . $partner->getSurname());

        return $ficha !== '' && mb_strtoupper($ficha) === $ficha ? mb_strtoupper($word) : $word;
    }

    /**
     * @param array{name: string, surname: string} $a
     * @param array{name: string, surname: string} $b
     */
    private static function same(array $a, array $b): bool
    {
        $norm = static fn (string $s): string => implode(' ', array_map(static fn (array $w): string => $w['key'], self::words($s)));

        return $norm($a['name']) === $norm($b['name']) && $norm($a['surname']) === $norm($b['surname']);
    }
}
