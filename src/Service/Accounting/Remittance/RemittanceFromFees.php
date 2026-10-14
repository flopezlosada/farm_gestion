<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\User;
use App\Service\Accounting\MonthNames;
use App\Service\Partner\Fee\FeeStatement;
use App\Service\Partner\Fee\MonthlyFees;

/**
 * El borrador de la remesa de cuotas de un mes, sacado del cálculo de las cuotas y no
 * del Excel del mes anterior: un recibo por cada persona a la que se le puede cobrar,
 * con su cuenta y su mandato de la ficha y lo que le sale ese mes.
 *
 * Quien no se puede cobrar (le falta un precio, no tiene mandato, no le sale nada) no
 * entra: sigue en «Cuotas del mes» con el motivo, y en la revisión de la remesa como
 * familia con cesta y sin recibo.
 */
class RemittanceFromFees
{
    /** El borrador del mes de esas cuotas, con un recibo por cada una que se puede cobrar. */
    public function build(MonthlyFees $fees, ?User $by): Remittance
    {
        $period = $fees->month;
        $label = sprintf('Recibos %s %s', mb_strtoupper(MonthNames::long((int) $period->format('n'))), $period->format('Y'));
        $remittance = new Remittance(Remittance::KIND_BASKET, $period, $label, Remittance::SOURCE_APP, $by);

        foreach ($fees->getChargeable() as $statement) {
            $remittance->addLine($this->line($statement, $period));
        }

        return $remittance;
    }

    /**
     * El recibo de una persona: los datos de cobro de su ficha y su cuota del mes.
     *
     * Tipo de adeudo FRST en todos, como se envía hoy y Fiare acepta (los 120 recibos
     * de agosto de 2026 lo llevan). La norma SEPA pediría RCUR desde el segundo cobro
     * de un mandato; no se cambia sin confirmarlo con el banco, porque un tipo que no
     * espera le haría rechazar la remesa entera.
     */
    private function line(FeeStatement $statement, \DateTimeImmutable $period): RemittanceLine
    {
        $payer = $statement->payer;
        $mandate = (string) $payer->getMandateReference();

        return (new RemittanceLine())
            ->setPartner($payer)
            ->setDebtorName(mb_substr(mb_strtoupper($payer->getLegalName()), 0, RemittanceLine::MAX_NAME))
            ->setIban($payer->getIban())
            ->setMandateReference($mandate)
            ->setMandateSignedOn($payer->getMandateSignedOn())
            ->setDebitReference(mb_substr($mandate . ' ' . $period->format('Ym'), 0, RemittanceLine::MAX_REFERENCE))
            ->setSequenceType(RemittanceLine::SEQUENCE_FIRST)
            ->setAmount($statement->getTotal());
    }
}
