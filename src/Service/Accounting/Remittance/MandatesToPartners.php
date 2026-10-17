<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Pasa a las fichas de socixs la cuenta y el mandato con los que se les cobró en una
 * remesa enviada: es como se rellenan de una vez los mandatos, que hasta ahora sólo
 * estaban en el Excel del banco.
 *
 * Sólo RELLENA lo que falta. Si la ficha ya tiene otro mandato u otra cuenta no la
 * pisa: lo cuenta como diferencia para que lo mire una persona. La remesa puede ser
 * vieja, y la ficha, lo que se corrigió después. La excepción es una cuenta de ficha
 * que ni siquiera es un IBAN válido cuando la del recibo sí lo es: es una errata al
 * copiarla, y la buena es con la que el banco cobró.
 *
 * En dos pasos: {@see plan()} dice qué haría sin tocar nada (la ficha de la remesa lo
 * enseña siempre) y {@see apply()} lo hace.
 */
class MandatesToPartners
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * Lo que se rellenaría y lo que no coincide, sin cambiar ninguna ficha.
     *
     * @param list<Partner> $holders Las fichas que ya tienen mandato, para no dar a
     *                               nadie uno que es de otrx (el banco no deja).
     *
     * @return array{
     *     mandates: list<array{partner: Partner, line: RemittanceLine}>,
     *     ibans: list<array{partner: Partner, line: RemittanceLine}>,
     *     repairs: list<array{partner: Partner, line: RemittanceLine}>,
     *     differences: list<array{partner: Partner, text: string}>,
     *     unlinked: int,
     * }
     */
    public function plan(Remittance $remittance, array $holders): array
    {
        $holderOf = [];
        foreach ($holders as $holder) {
            $holderOf[mb_strtoupper((string) $holder->getMandateReference())] = $holder;
        }

        $plan = ['mandates' => [], 'ibans' => [], 'repairs' => [], 'differences' => [], 'unlinked' => 0];
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
                $plan['differences'][] = ['partner' => $partner, 'text' => $difference];
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
                if (!$this->isIban($current) && $this->isIban($line->getIban())) {
                    $plan['repairs'][] = ['partner' => $partner, 'line' => $line];
                } else {
                    $plan['differences'][] = ['partner' => $partner, 'text' => $this->ibanDifference($current, $line)];
                }
            }
        }

        return $plan;
    }

    /**
     * Rellena las fichas según el plan. Sólo para una remesa enviada: lo que dice un
     * borrador aún no se ha cobrado a nadie.
     *
     * @param array{mandates: list<array{partner: Partner, line: RemittanceLine}>, ibans: list<array{partner: Partner, line: RemittanceLine}>, repairs: list<array{partner: Partner, line: RemittanceLine}>} $plan
     */
    public function apply(Remittance $remittance, array $plan): void
    {
        if (!$remittance->isSent()) {
            throw new \LogicException('Sólo se pasan a las fichas los datos de una remesa enviada.');
        }
        foreach ($plan['mandates'] as ['partner' => $partner, 'line' => $line]) {
            $partner->setMandateReference($line->getMandateReference())->setMandateSignedOn($line->getMandateSignedOn());
        }
        foreach ([...$plan['ibans'], ...$plan['repairs']] as ['partner' => $partner, 'line' => $line]) {
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
                : sprintf('La ficha tiene el mandato «%s» y en la remesa se le cobró con «%s».', $current, $reference);
        }
        $holder = $holderOf[mb_strtoupper($reference)] ?? null;
        if ($holder !== null && $holder !== $partner) {
            return sprintf('El mandato «%s» ya lo tiene %s.', $reference, $holder->getLegalName());
        }

        return $line->getMandateSignedOn() === null
            ? sprintf('El recibo no dice cuándo se firmó el mandato «%s».', $reference)
            : null;
    }

    /**
     * Las dos cuentas no coinciden. Si enmascaradas salen iguales («ES60 … 8199» las
     * dos), la diferencia está en las cifras del medio y hay que decirlo.
     */
    private function ibanDifference(string $current, RemittanceLine $line): string
    {
        $mine = RemittanceLine::maskIban($current);
        $theirs = $line->getMaskedIban();

        return sprintf(
            'La cuenta de la ficha (%s) no es la del recibo (%s)%s.',
            $mine, $theirs, $mine === $theirs ? ': cambian cifras del medio' : '',
        );
    }

    /** Si una cuenta es un IBAN válido: el país, el largo y el dígito de control. */
    private function isIban(string $iban): bool
    {
        return $this->validator->validate($iban, new Iban())->count() === 0;
    }
}
