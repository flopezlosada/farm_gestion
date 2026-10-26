<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\AccountEntry;
use App\Entity\IssuedInvoice;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Repository\AccountEntryRepository;
use App\Repository\RemittanceReturnRepository;

/**
 * Las devoluciones que trae el banco de una remesa, y qué recibos pueden ser cada una.
 *
 * Fiare pone en el concepto la referencia del lote: el abono es `ab.rem.<ref>` y
 * cada devolución `bel-liq.rem.devol<ref>` (más su comisión, `bel-com…`, que es un
 * gasto y no se liga a nada). El importe solo no dice de quién es una devolución
 * (55 € lo pagan decenas de recibos), así que se ofrecen los recibos de la remesa por
 * ese importe y la persona elige. El nombre sólo sale en el detalle del movimiento en
 * la web de Fiare.
 *
 * Una remesa no guarda su referencia: son las de los abonos de su mes. Un mes puede
 * tener varios lotes (la remesa y algún cobro suelto), y una devolución de cualquiera
 * de ellos se ofrece; sólo los recibos del importe justo la pueden explicar.
 */
class BankReturns
{
    public const CREDIT_PREFIX = 'ab.rem.';
    public const RETURN_PREFIX = 'bel-liq.rem.devol';

    /** Hasta cuándo, desde el día 1 del mes de la remesa, se buscan sus devoluciones. */
    private const RETURN_WINDOW = '+2 months -1 day';

    public function __construct(
        private readonly AccountEntryRepository $entries,
        private readonly RemittanceReturnRepository $returns,
    ) {
    }

    /**
     * Las devoluciones del banco de los lotes del mes de la remesa que aún no se han
     * ligado a ningún recibo.
     *
     * @return list<AccountEntry>
     */
    public function unlinkedFor(Remittance $remittance): array
    {
        $from = $remittance->getPeriod();
        $references = array_flip(array_filter(array_map(
            static fn (AccountEntry $e): ?string => self::reference($e->getConcept(), self::CREDIT_PREFIX),
            $this->entries->findByConceptPrefix(self::CREDIT_PREFIX, $from, $from->modify('last day of this month')),
        )));
        if ($references === []) {
            return [];
        }

        $found = array_values(array_filter(
            $this->entries->findByConceptPrefix(self::RETURN_PREFIX, $from, $from->modify(self::RETURN_WINDOW)),
            static fn (AccountEntry $e): bool => isset($references[self::reference($e->getConcept(), self::RETURN_PREFIX)]),
        ));
        $linked = array_flip($this->returns->findLinkedEntryIds($found));

        return array_values(array_filter($found, static fn (AccountEntry $e): bool => !isset($linked[$e->getId()])));
    }

    /**
     * Los recibos de la remesa que pueden ser esa devolución: los del mismo importe que
     * aún no se han dado por devueltos.
     *
     * @param list<RemittanceReturn> $returns Las devoluciones que ya tiene la remesa.
     *
     * @return list<RemittanceLine>
     */
    public static function candidatesFor(AccountEntry $entry, Remittance $remittance, array $returns): array
    {
        $cents = -IssuedInvoice::toCents($entry->getAmount());

        return array_values(array_filter(
            self::notReturned($remittance, $returns),
            static fn (RemittanceLine $l): bool => IssuedInvoice::toCents($l->getAmount()) === $cents,
        ));
    }

    /**
     * Los recibos de la remesa que aún no se han dado por devueltos.
     *
     * @param list<RemittanceReturn> $returns Las devoluciones que ya tiene la remesa.
     *
     * @return list<RemittanceLine>
     */
    public static function notReturned(Remittance $remittance, array $returns): array
    {
        $returned = array_flip(array_map(static fn (RemittanceReturn $r): int => (int) $r->getLine()->getId(), $returns));

        return array_values($remittance->getLines()->filter(
            static fn (RemittanceLine $l): bool => !isset($returned[$l->getId()]),
        )->toArray());
    }

    /** La referencia del lote en un concepto de Fiare, o null si no es de ese tipo. */
    public static function reference(string $concept, string $prefix): ?string
    {
        return preg_match('/^' . preg_quote($prefix, '/') . '(\d+)/i', trim($concept), $m) === 1 ? $m[1] : null;
    }
}
