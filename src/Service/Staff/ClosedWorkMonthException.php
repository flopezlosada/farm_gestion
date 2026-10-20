<?php

namespace App\Service\Staff;

/**
 * Se ha intentado cambiar el registro de un mes ya aprobado. El mensaje está
 * pensado para enseñarse tal cual: dice qué mes es y qué hay que hacer.
 */
class ClosedWorkMonthException extends \DomainException
{
    /**
     * @param WorkMonth $month El mes cerrado.
     */
    public function __construct(WorkMonth $month)
    {
        parent::__construct(sprintf(
            'El registro de %s ya está aprobado y no se puede cambiar. Para corregirlo, alguien de gestión laboral tiene que reabrir el mes.',
            $month->label(),
        ));
    }
}
