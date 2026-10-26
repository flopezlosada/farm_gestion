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

    /** El recibo de una persona: los datos de cobro de su ficha y su cuota del mes. */
    private function line(FeeStatement $statement, \DateTimeImmutable $period): RemittanceLine
    {
        $payer = $statement->payer;

        return RemittanceLine::forPayer($payer, $statement->getTotal(), $payer->getMandateReference().' '.$period->format('Ym'));
    }
}
