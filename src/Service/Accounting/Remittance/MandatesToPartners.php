<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;

/**
 * Pasa a las fichas de socixs la cuenta y el mandato con los que se les cobró en una
 * remesa enviada: es como se rellenan de una vez los mandatos, que hasta ahora sólo
 * estaban en el Excel del banco.
 *
 * Sólo RELLENA lo que falta. Si la ficha ya tiene otro mandato u otra cuenta no la
 * pisa: lo cuenta como diferencia para que lo mire una persona. La remesa puede ser
 * vieja, y la ficha, lo que se corrigió después.
 *
 * En dos pasos: {@see plan()} dice qué haría sin tocar nada (la ficha de la remesa lo
 * enseña siempre) y {@see apply()} lo hace.
 */
class MandatesToPartners
{
    /**
     * Lo que se rellenaría y lo que no coincide, sin cambiar ninguna ficha.
     *
     * @param list<Partner> $holders Las fichas que ya tienen mandato, para no dar a
     *                               nadie uno que es de otrx (el banco no deja).
     *
     * @return array{
     *     mandates: list<array{partner: Partner, line: RemittanceLine}>,
     *     ibans: list<array{partner: Partner, line: RemittanceLine}>,
     *     differences: list<string>,
     *     unlinked: int,
     * }
     */
    public function plan(Remittance $remittance, array $holders): array
    {
        $holderOf = [];
        foreach ($holders as $holder) {
            $holderOf[mb_strtoupper((string) $holder->getMandateReference())] = $holder;
        }

        $plan = ['mandates' => [], 'ibans' => [], 'differences' => [], 'unlinked' => 0];
        // Lo que ya va a recibir cada ficha en este plan: quien tiene dos recibos no
        // puede llevarse dos mandatos ni dos cuentas.
        $gettingMandate = [];
        $gettingIban = [];
        foreach ($remittance->getLines() as $line) {
            $partner = $line->getPartner();
            if ($partner === null) {
                ++$plan['unlinked'];
                continue;
            }

            $currentMandate = $partner->getMandateReference() ?? $gettingMandate[spl_object_id($partner)] ?? null;
            $difference = $this->mandateDifference($line, $partner, $currentMandate, $holderOf);
            if ($difference !== null) {
                $plan['differences'][] = $difference;
            } elseif ($currentMandate === null) {
                $plan['mandates'][] = ['partner' => $partner, 'line' => $line];
                $holderOf[mb_strtoupper($line->getMandateReference())] = $partner;
                $gettingMandate[spl_object_id($partner)] = $line->getMandateReference();
            }

            $current = $partner->getIban() ?? $gettingIban[spl_object_id($partner)] ?? null;
            if ($current === null) {
                $plan['ibans'][] = ['partner' => $partner, 'line' => $line];
                $gettingIban[spl_object_id($partner)] = $line->getIban();
            } elseif ($current !== $line->getIban()) {
                $plan['differences'][] = sprintf('%s: la cuenta de la ficha (%s) no es la del recibo (%s).', $partner->getLegalName(), RemittanceLine::maskIban($current), $line->getMaskedIban());
            }
        }

        return $plan;
    }

    /**
     * Rellena las fichas según el plan. Sólo para una remesa enviada: lo que dice un
     * borrador aún no se ha cobrado a nadie.
     *
     * @param array{mandates: list<array{partner: Partner, line: RemittanceLine}>, ibans: list<array{partner: Partner, line: RemittanceLine}>} $plan
     */
    public function apply(Remittance $remittance, array $plan): void
    {
        if (!$remittance->isSent()) {
            throw new \LogicException('Sólo se pasan a las fichas los datos de una remesa enviada.');
        }
        foreach ($plan['mandates'] as ['partner' => $partner, 'line' => $line]) {
            $partner->setMandateReference($line->getMandateReference())->setMandateSignedOn($line->getMandateSignedOn());
        }
        foreach ($plan['ibans'] as ['partner' => $partner, 'line' => $line]) {
            $partner->setIban($line->getIban());
        }
    }

    /**
     * Por qué el mandato del recibo no puede ir a la ficha, o null si puede (o ya
     * está).
     *
     * @param string|null            $current  El que tiene la ficha o ya se le va a poner.
     * @param array<string, Partner> $holderOf
     */
    private function mandateDifference(RemittanceLine $line, Partner $partner, ?string $current, array $holderOf): ?string
    {
        $reference = $line->getMandateReference();
        if ($current !== null) {
            return mb_strtoupper($current) === mb_strtoupper($reference)
                ? null
                : sprintf('%s: la ficha tiene el mandato «%s» y en la remesa se le cobró con «%s».', $partner->getLegalName(), $current, $reference);
        }
        $holder = $holderOf[mb_strtoupper($reference)] ?? null;
        if ($holder !== null && $holder !== $partner) {
            return sprintf('%s: el mandato «%s» ya lo tiene %s.', $partner->getLegalName(), $reference, $holder->getLegalName());
        }

        return $line->getMandateSignedOn() === null
            ? sprintf('%s: el recibo no dice cuándo se firmó el mandato «%s».', $partner->getLegalName(), $reference)
            : null;
    }
}
