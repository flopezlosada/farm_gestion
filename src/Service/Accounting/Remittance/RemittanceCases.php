<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;

/**
 * Los recibos de una remesa enviada que no casan con la ficha de su socix, uno a uno,
 * para resolverlos A MANO: cada caso dice qué pasa con palabras y lleva lo que hace
 * falta para proponer un arreglo (la ficha candidata, las dos cuentas…). Lo decide
 * una persona: ningún botón arregla todos de golpe.
 *
 * Un recibo da como mucho un caso, con todas sus pegas dentro: es una fila en la
 * pantalla, no tres.
 */
class RemittanceCases
{
    /** Sin socix: hay que decir de quién es. */
    public const KIND_UNLINKED = 'unlinked';

    /** La cuenta de la ficha ni siquiera es un IBAN válido y la del recibo sí. */
    public const KIND_BAD_IBAN = 'bad_iban';

    /** La ficha tiene otra cuenta u otro mandato, válidos: hay que elegir cuál es el bueno. */
    public const KIND_OTHER = 'other';

    /** A la ficha le falta la cuenta, el mandato o los dos, y el recibo los tiene. */
    public const KIND_MISSING = 'missing';

    /** Algo no casa pero no se puede pasar a la ficha (el mandato es de otrx, sin fecha). */
    public const KIND_BLOCKED = 'blocked';

    /** Cuántas fichas parecidas se proponen como mucho para un recibo sin socix. */
    private const MAX_CANDIDATES = 3;

    public function __construct(private readonly MandatesToPartners $transfer)
    {
    }

    /**
     * @param list<Partner> $holders  Las fichas con mandato ({@see MandatesToPartners::plan()}).
     * @param list<Partner> $partners Todas, para buscar la de un recibo sin socix.
     *
     * @return array{open: list<array{line: RemittanceLine, partner: ?Partner, kind: string, details: list<string>, candidates: list<array{partner: Partner, reasons: list<string>, rename: array{name: string, surname: string}|null, score: int}>, fills: list<string>, fixable: bool, ibans: list<array{a: string, b: string, differs: bool, chars: list<array{a: string, b: string, differs: bool}>}>|null, siblings: int}>, reviewed: list<RemittanceLine>}
     */
    public function build(Remittance $remittance, array $holders, array $partners): array
    {
        $plan = $this->transfer->plan($remittance, $holders);
        $open = [];
        $reviewed = [];
        $seen = [];
        foreach ($remittance->getLines() as $line) {
            // Los recibos de una misma persona sin socix (la cuota y el alta) son un
            // solo caso: se enlazan juntos ({@see siblings()}).
            if ($line->getPartner() === null) {
                $key = self::holderKey($line);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $case = $line->getPartner() === null
                ? $this->unlinked($line, $partners)
                : $this->linked($line, $line->getPartner(), $plan);
            if ($case === null) {
                continue;
            }
            $case['siblings'] = \count(self::siblings($remittance, $line));
            if ($line->getReviewedAt() !== null) {
                $reviewed[] = $line;
            } else {
                $open[] = $case;
            }
        }

        return ['open' => $open, 'reviewed' => $reviewed];
    }

    /**
     * @param list<Partner> $partners
     *
     * @return array{line: RemittanceLine, partner: null, kind: string, details: list<string>, candidates: list<array{partner: Partner, reasons: list<string>, rename: array{name: string, surname: string}|null, score: int}>, fills: list<string>, fixable: bool, ibans: null}
     */
    private function unlinked(RemittanceLine $line, array $partners): array
    {
        return [
            'line' => $line,
            'partner' => null,
            'kind' => self::KIND_UNLINKED,
            'details' => [],
            'candidates' => \array_slice(PartnerNameMatcher::candidates($line->getDebtorName(), $partners), 0, self::MAX_CANDIDATES),
            'fills' => [],
            'fixable' => false,
            'ibans' => null,
        ];
    }

    /**
     * El caso de un recibo con socix, o null si casa con su ficha.
     *
     * @param array{mandates: list<array{partner: Partner, line: RemittanceLine}>, ibans: list<array{partner: Partner, line: RemittanceLine}>, repairs: list<array{partner: Partner, line: RemittanceLine}>, differences: list<array{partner: Partner, line: RemittanceLine, field: string, text: string}>} $plan
     *
     * @return array{line: RemittanceLine, partner: Partner, kind: string, details: list<string>, candidates: list<never>, fills: list<string>, fixable: bool, ibans: list<array{a: string, b: string, differs: bool, chars: list<array{a: string, b: string, differs: bool}>}>|null}|null
     */
    private function linked(RemittanceLine $line, Partner $partner, array $plan): ?array
    {
        $mine = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => $r['line'] === $line));
        $differences = $mine($plan['differences']);
        $fields = array_column($differences, 'field');
        $repair = $mine($plan['repairs']) !== [];
        $fills = array_merge(
            $mine($plan['ibans']) !== [] ? ['la cuenta'] : [],
            $mine($plan['mandates']) !== [] ? ['el mandato'] : [],
        );
        if ($differences === [] && !$repair && $fills === []) {
            return null;
        }

        $kind = match (true) {
            $repair => self::KIND_BAD_IBAN,
            \in_array(MandatesToPartners::FIELD_IBAN, $fields, true), \in_array(MandatesToPartners::FIELD_MANDATE, $fields, true) => self::KIND_OTHER,
            $fills !== [] => self::KIND_MISSING,
            default => self::KIND_BLOCKED,
        };
        $showIbans = $repair || \in_array(MandatesToPartners::FIELD_IBAN, $fields, true);

        return [
            'line' => $line,
            'partner' => $partner,
            'kind' => $kind,
            'details' => array_column($differences, 'text'),
            'candidates' => [],
            'fills' => $fills,
            'fixable' => $this->transfer->forLine($plan, $line) !== ['mandates' => [], 'ibans' => [], 'repairs' => []],
            'ibans' => $showIbans ? self::ibanGroups((string) $partner->getIban(), $line->getIban()) : null,
        ];
    }

    /**
     * Los recibos de la remesa que son de la misma persona que éste y siguen sin
     * socix (él incluido): mismo mandato y mismo nombre. Lo que se decide con uno
     * vale para todos.
     *
     * @return list<RemittanceLine>
     */
    public static function siblings(Remittance $remittance, RemittanceLine $line): array
    {
        if ($line->getPartner() !== null) {
            return [$line];
        }
        $key = self::holderKey($line);

        return array_values(array_filter(
            $remittance->getLines()->toArray(),
            static fn (RemittanceLine $other): bool => $other->getPartner() === null && self::holderKey($other) === $key,
        ));
    }

    private static function holderKey(RemittanceLine $line): string
    {
        return mb_strtoupper($line->getMandateReference()) . '|' . RemittancePartnerMatcher::nameKey($line->getDebtorName());
    }

    /**
     * Las dos cuentas de cuatro en cuatro, marcando en qué cifras cambian: lo que hace
     * falta para ver una errata de un vistazo.
     *
     * @return list<array{a: string, b: string, differs: bool, chars: list<array{a: string, b: string, differs: bool}>}>
     *         un grupo de cuatro por elemento; a es la de la ficha y b la del recibo
     */
    public static function ibanGroups(string $a, string $b): array
    {
        $groups = [];
        $length = max(\strlen($a), \strlen($b));
        for ($i = 0; $i < $length; $i += 4) {
            $chars = [];
            for ($j = $i; $j < min($i + 4, $length); ++$j) {
                $x = $a[$j] ?? '';
                $y = $b[$j] ?? '';
                $chars[] = ['a' => $x, 'b' => $y, 'differs' => $x !== $y];
            }
            $groups[] = ['a' => substr($a, $i, 4), 'b' => substr($b, $i, 4), 'differs' => substr($a, $i, 4) !== substr($b, $i, 4), 'chars' => $chars];
        }

        return $groups;
    }
}
