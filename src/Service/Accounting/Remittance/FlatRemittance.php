<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\User;

/**
 * El borrador de una remesa del mismo importe para todas las personas de una base: una
 * derrama, una cuota de socix. Sólo entra quien tiene mandato completo; el resto se
 * cuenta para avisar, porque no se le puede domiciliar.
 */
class FlatRemittance
{
    /**
     * Lo que se añade a la referencia del adeudo para que no coincida con la del recibo
     * de cesta del mismo mes y mandato.
     */
    private const REFERENCE_SUFFIX = [
        Remittance::KIND_MEMBERSHIP => 'CS',
        Remittance::KIND_OTHER => 'EX',
    ];

    /**
     * El borrador, con un recibo de `$amount` por cada persona que se puede domiciliar.
     *
     * @param string        $kind   Remittance::KIND_MEMBERSHIP o KIND_OTHER.
     * @param list<Partner> $payers La base ya resuelta; se repite a alguien, sale una vez.
     *
     * @throws \InvalidArgumentException Si el tipo es el de cuotas de cesta, que sale del cálculo.
     */
    public function build(string $kind, \DateTimeImmutable $period, string $label, string $amount, array $payers, ?User $by): Remittance
    {
        $suffix = self::REFERENCE_SUFFIX[$kind] ?? throw new \InvalidArgumentException(sprintf('Una remesa de «%s» no es de importe fijo.', $kind));
        $remittance = new Remittance($kind, $period, $label, Remittance::SOURCE_APP, $by);
        $reference = static fn (Partner $p): string => sprintf('%s %s%s', $p->getMandateReference(), $period->format('Ym'), $suffix);

        $unique = array_combine(array_map(spl_object_id(...), $payers), $payers);
        foreach (array_filter($unique, static fn (Partner $p): bool => $p->hasDirectDebit()) as $payer) {
            $remittance->addLine(RemittanceLine::forPayer($payer, $amount, $reference($payer)));
        }

        return $remittance;
    }
}
