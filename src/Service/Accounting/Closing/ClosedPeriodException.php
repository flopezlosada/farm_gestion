<?php

namespace App\Service\Accounting\Closing;

use App\Entity\AccountantDelivery;

/**
 * Se ha intentado cambiar algo fechado en un trimestre ya entregado a la gestoría.
 * El mensaje se enseña tal cual a quien lo intentó.
 */
final class ClosedPeriodException extends \DomainException
{
    public function __construct(public readonly AccountantDelivery $delivery)
    {
        parent::__construct(sprintf(
            'El %s ya se entregó a la gestoría el %s: no se puede cambiar nada fechado en él. Si hace falta corregirlo, reábrelo desde «Para la gestoría» y vuelve a mandarlo.',
            $delivery->getQuarter()->label(),
            $delivery->getDeliveredAt()->format('d/m/Y'),
        ));
    }
}
