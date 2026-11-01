<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
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
     * cuotas. No es un error (hay quien paga por transferencia), pero un alta nueva
     * aparece aquí hasta que se le añade su recibo.
     *
     * @param array<int, FeeStatement> $expected La cuota calculada del mes, por quien encabeza la familia.
     *
     * @return list<array{partner: Partner, amount: string}>
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
            static fn (FeeStatement $fee): array => ['partner' => $fee->payer, 'amount' => $fee->getTotal()],
            array_values($missing),
        );
        usort($rows, static fn (array $a, array $b): int => strcmp($a['partner']->getLegalName(), $b['partner']->getLegalName()));

        return $rows;
    }

    /**
     * Qué cambia de la remesa anterior a ésta, por familia (o por mandato, si el
     * recibo no tiene socix). Lo que no cambia no sale.
     *
     * @return list<array{name: string, before: ?string, after: ?string, change: string}>
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
     * @return array<string, array{name: string, cents: int}>
     */
    private function totalsByKey(Remittance $remittance): array
    {
        $totals = [];
        foreach ($remittance->getLines() as $line) {
            $head = $line->getPartner() !== null ? $line->getPartner()->getFamilyHead() : null;
            $key = $head !== null ? 'p' . $head->getId() : 'm' . mb_strtoupper($line->getMandateReference());
            $totals[$key] = [
                'name' => $totals[$key]['name'] ?? ($head?->getLegalName() ?: $line->getDebtorName()),
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
