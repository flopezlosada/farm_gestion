<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Partner\Fee\FeeLine;
use App\Service\Partner\Fee\FeeStatement;
use App\Service\Partner\Fee\MonthlyFees;

/**
 * Lo que hay que mirar de una remesa antes de enviarla, y lo que ha cambiado respecto
 * a la anterior.
 *
 * Contrasta los recibos con la cuota calculada, pero sólo para AVISAR: lo que se cobra lo
 * decide quien revisa. Se compara por FAMILIA (quien la encabeza), porque la cesta
 * cuelga de un miembro y el recibo puede ir a nombre de otro, y por la suma, porque
 * hay quien tiene dos recibos (cuota normal y adicional).
 *
 * La lista de cambios es lo que contabilidad pidió primero: quién sube, quién baja,
 * quién entra y quién sale de un mes a otro, para no repasar la remesa entera.
 *
 * No consulta la base: las cuotas del mes, por quien paga, se las da
 * {@see MonthlyFees::getStatementsByPayerId()}.
 */
class RemittanceReview
{
    public const CHANGE_NEW = 'new';
    public const CHANGE_GONE = 'gone';
    public const CHANGE_AMOUNT = 'amount';

    /** Por qué falta el recibo de quien tiene todo para cobrarle. */
    public const NOTHING_MISSING = 'Tiene todo para cobrarle: falta su recibo en esta remesa.';

    /**
     * Avisos de cada recibo de un borrador, por id de recibo. Una remesa enviada no se
     * revisa: las cestas de hoy no dicen nada de lo que se cobró hace meses.
     *
     * @param array<int, FeeStatement> $expected La cuota calculada del mes, por quien encabeza la familia.
     *
     * @return array<int, list<string>>
     */
    public function lineWarnings(Remittance $remittance, array $expected): array
    {
        if (!$remittance->isDraft()) {
            return [];
        }

        $expected = $remittance->isBasketFees() ? $expected : null;
        $charged = $this->chargedByFamily($remittance);

        $warnings = [];
        foreach ($remittance->getLines() as $line) {
            $partner = $line->getPartner();
            $root = $partner !== null ? $partner->getFamilyHead() : null;
            $warnings[(int) $line->getId()] = array_values(array_filter([
                $partner === null ? 'Sin socix: no se puede contrastar con sus cestas.' : null,
                $partner?->getStatus() === Partner::STATUS_BAJA ? 'Está de baja.' : null,
                $partner?->getStatus() === Partner::STATUS_PAUSADO ? 'Está en pausa.' : null,
                $root !== null && $expected !== null ? self::feeWarning($expected[$root->getId()] ?? null, $charged[$root->getId()]) : null,
            ]));
        }

        return $warnings;
    }

    /**
     * Familias con cesta activa en el mes que no tienen recibo en esta remesa de
     * cuotas, con el porqué: lo que le falta para cobrarle (casi siempre el mandato) o,
     * si no le falta nada, que se quitó su recibo a mano o la remesa se copió de otra.
     *
     * @param array<int, FeeStatement> $expected La cuota calculada del mes, por quien encabeza la familia.
     *
     * @return list<array{partner: Partner, amount: string, reasons: non-empty-list<string>}>
     */
    public function familiesWithoutLine(Remittance $remittance, array $expected): array
    {
        if (!$remittance->isDraft() || !$remittance->isBasketFees()) {
            return [];
        }

        $charged = $this->chargedByFamily($remittance);
        $missing = array_filter(
            $expected,
            static fn (FeeStatement $fee, int $rootId): bool => $fee->getCents() > 0 && !isset($charged[$rootId]),
            ARRAY_FILTER_USE_BOTH,
        );
        $rows = array_map(
            static fn (FeeStatement $fee): array => [
                'partner' => $fee->payer,
                'amount' => $fee->getTotal(),
                'reasons' => $fee->getProblems() ?: [self::NOTHING_MISSING],
            ],
            array_values($missing),
        );
        usort($rows, static fn (array $a, array $b): int => strcmp($a['partner']->getLegalName(), $b['partner']->getLegalName()));

        return $rows;
    }

    /**
     * Qué cambia de la remesa anterior a ésta, por familia (o por mandato, si el
     * recibo no tiene socix). Lo que no cambia no sale.
     *
     * @return list<array{name: string, partner: ?Partner, before: ?string, after: ?string, change: string}>
     */
    public function changesFrom(?Remittance $previous, Remittance $current): array
    {
        if ($previous === null) {
            return [];
        }

        $before = $this->totalsByKey($previous);
        $after = $this->totalsByKey($current);

        $rows = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if ($b !== null && $a !== null && $b['cents'] === $a['cents']) {
                continue;
            }
            $rows[] = [
                'name' => ($a ?? $b)['name'],
                'partner' => ($a ?? $b)['partner'],
                'before' => $b !== null ? IssuedInvoice::fromCents($b['cents']) : null,
                'after' => $a !== null ? IssuedInvoice::fromCents($a['cents']) : null,
                'change' => match (true) {
                    $b === null => self::CHANGE_NEW,
                    $a === null => self::CHANGE_GONE,
                    default => self::CHANGE_AMOUNT,
                },
            ];
        }
        usort($rows, static fn (array $x, array $y): int => strcmp($x['name'], $y['name']));

        return $rows;
    }


    /**
     * El porqué de cada cambio según lo que sabe la app: qué cesta empieza o se deja, a
     * cuál le cambia la cuota y si hay ajustes, sacado de la cuota calculada de los dos
     * meses (calculada hoy: lo que la app sabe ahora de los dos). Un cambio está
     * explicado si la app da algún porqué Y su cuota calculada cambia lo mismo que lo
     * cobrado, o si quien sale está de baja; si no, hay que mirarlo, y se dice en qué
     * mes lo cobrado no es lo que calcula la app: ahí está la diferencia.
     *
     * @param list<array{name: string, partner: ?Partner, before: ?string, after: ?string, change: string}> $changes
     * @param array<int, FeeStatement> $before      La cuota calculada del mes de la anterior, por quien encabeza la familia.
     * @param array<int, FeeStatement> $now         La de este mes.
     * @param \DateTimeInterface       $beforeMonth El mes de la anterior.
     * @param \DateTimeInterface       $nowMonth    El de ésta.
     *
     * @return list<array{name: string, partner: ?Partner, before: ?string, after: ?string, change: string, reasons: list<string>, explained: bool}>
     */
    public function explainChanges(array $changes, array $before, array $now, \DateTimeInterface $beforeMonth, \DateTimeInterface $nowMonth): array
    {
        return array_map(static function (array $row) use ($before, $now, $beforeMonth, $nowMonth): array {
            $partner = $row['partner'];
            if ($partner === null) {
                return $row + ['reasons' => ['Recibo sin socix: la app no sabe de quién es.'], 'explained' => false];
            }
            $id = (int) $partner->getId();
            $reasons = self::reasonsFor($before[$id] ?? null, $now[$id] ?? null);
            // Quien sale y está de baja no necesita más: la cuota de antes, calculada
            // hoy, ya no le cuenta las cestas y no se puede comparar.
            $leftForGood = $row['change'] === self::CHANGE_GONE && $partner->getStatus() === Partner::STATUS_BAJA;
            if ($leftForGood) {
                $reasons[] = 'Está de baja.';
            }
            $calculatedBefore = ($before[$id] ?? null)?->getCents() ?? 0;
            $calculatedNow = ($now[$id] ?? null)?->getCents() ?? 0;
            $chargedBefore = IssuedInvoice::toCents($row['before'] ?? '0');
            $chargedNow = IssuedInvoice::toCents($row['after'] ?? '0');
            $explained = $leftForGood || ($reasons !== [] && $calculatedNow - $calculatedBefore === $chargedNow - $chargedBefore);
            if (!$explained) {
                // Lo que se entiende: en qué mes lo cobrado no es lo que calcula la app.
                // Va delante porque es lo que hay que mirar; lo de las cestas sólo
                // cuenta de dónde salen las cifras de la app.
                $reasons = [
                    ...array_filter([
                        self::mismatch($beforeMonth, 'se le cobraron', 'no se le cobró nada', $chargedBefore, $calculatedBefore),
                        self::mismatch($nowMonth, 'se le cobran', 'no se le cobra nada', $chargedNow, $calculatedNow),
                    ]),
                    ...$reasons,
                ];
            }

            return $row + ['reasons' => $reasons, 'explained' => $explained];
        }, $changes);
    }

    /**
     * Las sumas de los cambios: lo que entra, lo que sale, lo que suben y bajan los que
     * cambian, y el neto. El neto tiene que ser lo que va de una remesa a la otra; si no,
     * la comparación se ha dejado algo.
     *
     * @param list<array{before: ?string, after: ?string, change: string}> $changes
     *
     * @return array{new: string, gone: string, up: string, down: string, net: string, expectedNet: string, addsUp: bool}
     */
    public function changesTotals(array $changes, Remittance $previous, Remittance $current): array
    {
        $cents = static fn (?string $amount): int => IssuedInvoice::toCents($amount ?? '0');
        $sum = static fn (string $change, callable $of): int => array_sum(array_map(
            $of,
            array_values(array_filter($changes, static fn (array $c): bool => $c['change'] === $change)),
        ));
        $deltas = array_map(static fn (array $c): int => $cents($c['after']) - $cents($c['before']), array_values(array_filter($changes, static fn (array $c): bool => $c['change'] === self::CHANGE_AMOUNT)));
        $new = $sum(self::CHANGE_NEW, static fn (array $c): int => $cents($c['after']));
        $gone = $sum(self::CHANGE_GONE, static fn (array $c): int => $cents($c['before']));
        $up = array_sum(array_filter($deltas, static fn (int $d): bool => $d > 0));
        $down = array_sum(array_filter($deltas, static fn (int $d): bool => $d < 0));
        $net = $new - $gone + $up + $down;
        $expected = $cents($current->getTotal()) - $cents($previous->getTotal());

        return [
            'new' => IssuedInvoice::fromCents($new),
            'gone' => IssuedInvoice::fromCents($gone),
            'up' => IssuedInvoice::fromCents($up),
            'down' => IssuedInvoice::fromCents($down),
            'net' => IssuedInvoice::fromCents($net),
            'expectedNet' => IssuedInvoice::fromCents($expected),
            'addsUp' => $net === $expected,
        ];
    }

    /**
     * Qué cambia entre la cuota calculada de un mes y la del siguiente: las cestas que
     * empiezan, las que se dejan, las que cuestan otra cosa, y los ajustes.
     *
     * @return list<string>
     */
    private static function reasonsFor(?FeeStatement $before, ?FeeStatement $now): array
    {
        $byShare = static function (?FeeStatement $fee): array {
            $lines = [];
            foreach ($fee?->getLines() ?? [] as $line) {
                $lines[spl_object_id($line->share)] = $line;
            }

            return $lines;
        };
        $old = $byShare($before);
        $new = $byShare($now);
        $reasons = [];
        foreach (array_diff_key($new, $old) as $line) {
            $start = $line->share->getStartDate();
            $reasons[] = sprintf('Empieza %s%s.', self::shareLabel($line), $start instanceof \DateTimeInterface ? ' el ' . $start->format('d/m/Y') : '');
        }
        foreach (array_diff_key($old, $new) as $line) {
            $end = $line->share->getEndDate();
            $reasons[] = sprintf('Deja %s%s.', self::shareLabel($line), $end instanceof \DateTimeInterface ? ' el ' . $end->format('d/m/Y') : '');
        }
        foreach (array_intersect_key($new, $old) as $key => $line) {
            if ($line->getCents() !== $old[$key]->getCents()) {
                $reasons[] = sprintf('%s pasa de %s € a %s €.', ucfirst(self::shareLabel($line)), self::euros($old[$key]->getCents()), self::euros($line->getCents()));
            }
        }
        $extras = static fn (?FeeStatement $fee): int => ($fee?->getAdjustmentsCents() ?? 0) + ($fee?->getChargesCents() ?? 0);
        if ($extras($before) !== $extras($now)) {
            $reasons[] = sprintf('Ajustes y cargos de cuota: %s € → %s €.', self::euros($extras($before)), self::euros($extras($now)));
        }

        return $reasons;
    }

    /** «la cesta quincenal» o, si es de otra persona de la familia, «la cesta quincenal de Lucía». */
    private static function shareLabel(FeeLine $line): string
    {
        $modality = $line->share->getBasketShare()?->getName();
        $label = $modality !== null ? 'la cesta ' . mb_strtolower($modality) : 'la cesta';
        $owner = $line->share->getPartner();

        return $owner instanceof Partner && $owner !== $line->share->getEffectivePayer() ? $label . ' de ' . $owner->getName() : $label;
    }

    /**
     * «En octubre se le cobraron 67,00 €, pero la app calcula 12,20 €.», o null si ese
     * mes lo cobrado es lo que calcula la app.
     *
     * @param string $charged Cómo se dice que se cobró algo ese mes («se le cobraron»).
     * @param string $nothing Cómo se dice que no se cobró nada («no se le cobró nada»).
     */
    private static function mismatch(\DateTimeInterface $month, string $charged, string $nothing, int $chargedCents, int $calculatedCents): ?string
    {
        if ($chargedCents === $calculatedCents) {
            return null;
        }
        $name = (new \IntlDateFormatter('es_ES', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'LLLL'))->format($month);

        return sprintf(
            'En %s %s, pero la app %s.',
            $name,
            $chargedCents === 0 ? $nothing : $charged . ' ' . self::euros($chargedCents) . ' €',
            $calculatedCents === 0 ? 'no le calcula nada' : 'calcula ' . self::euros($calculatedCents) . ' €',
        );
    }

    /**
     * El aviso cuando lo que se cobra a una familia no es su cuota calculada. Si a la
     * cuota le falta algún precio no se puede contrastar: la cifra saldría corta.
     */
    private static function feeWarning(?FeeStatement $expected, int $chargedCents): ?string
    {
        if ($expected === null) {
            return 'No tiene ninguna cesta activa este mes.';
        }
        if (($missing = $expected->describeMissing()) !== null) {
            return sprintf('Falta el precio de %s: no se puede contrastar su cuota.', $missing);
        }
        if ($expected->getCents() === $chargedCents) {
            return null;
        }

        return sprintf(
            'Su cuota calculada es %s € al mes y en esta remesa se le cobran %s €.',
            self::euros($expected->getCents()),
            self::euros($chargedCents),
        );
    }

    /**
     * Lo que se cobra a cada familia en la remesa, en céntimos.
     *
     * @return array<int, int>
     */
    private function chargedByFamily(Remittance $remittance): array
    {
        $charged = [];
        foreach ($remittance->getLines() as $line) {
            if ($line->getPartner() !== null) {
                $id = $line->getPartner()->getFamilyHead()->getId();
                $charged[$id] = ($charged[$id] ?? 0) + IssuedInvoice::toCents($line->getAmount());
            }
        }

        return $charged;
    }

    /**
     * Total por familia, o por mandato si el recibo no tiene socix.
     *
     * @return array<string, array{name: string, partner: ?Partner, cents: int}>
     */
    private function totalsByKey(Remittance $remittance): array
    {
        $totals = [];
        foreach ($remittance->getLines() as $line) {
            $head = $line->getPartner() !== null ? $line->getPartner()->getFamilyHead() : null;
            $key = $head !== null ? 'p' . $head->getId() : 'm' . mb_strtoupper($line->getMandateReference());
            $totals[$key] = [
                'name' => $totals[$key]['name'] ?? ($head?->getLegalName() ?: $line->getDebtorName()),
                'partner' => $head,
                'cents' => ($totals[$key]['cents'] ?? 0) + IssuedInvoice::toCents($line->getAmount()),
            ];
        }

        return $totals;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
