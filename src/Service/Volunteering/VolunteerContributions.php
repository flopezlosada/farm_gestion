<?php

namespace App\Service\Volunteering;

use App\Entity\Partner;
use App\Repository\VolunteerCoordinationLogRepository;
use App\Repository\VolunteerSignupRepository;

/**
 * Cuánto lleva aportado cada socix en el periodo en curso, y cuánto lleva quien
 * participa. Las dos pantallas que lo pintan —la home del panel y la de
 * voluntariado— preguntan aquí.
 *
 * EL PERIODO VIVE EN ESTE SITIO Y EN NINGÚN OTRO. Es año natural y no
 * "temporada" porque no hay ninguna temporada definida en el sistema; el día que
 * la asamblea acuerde una, {@see period()} es lo único que hay que tocar. Estaba
 * como método privado de un controlador con esa misma promesa escrita, y la
 * promesa dejaba de ser cierta en cuanto una segunda pantalla necesitara el
 * mismo periodo.
 */
class VolunteerContributions
{
    public function __construct(
        private readonly VolunteerSignupRepository $signups,
        private readonly VolunteerCoordinationLogRepository $coordination,
    ) {
    }

    /**
     * Lo aportado por este socix frente a la referencia del colectivo.
     *
     * @param Partner $partner el socix
     *
     * @return VolunteerContribution sus minutos y la mediana del periodo
     */
    public function forPartner(Partner $partner): VolunteerContribution
    {
        [$from, $to] = $this->period();

        // Las horas de coordinar un área se suman a las de las tareas: para
        // quien mira lo suyo es todo lo mismo —tiempo que le ha dedicado a la
        // asociación— y partirlo en dos cifras le obligaría a sumarlas de
        // cabeza. La mediana NO las incluye a propósito: es la referencia de
        // "lo que hace quien echa una mano", y meter ahí a las cuatro personas
        // que coordinan la subiría por encima de lo que hace cualquiera.
        return new VolunteerContribution(
            $this->signups->sumCreditedMinutes($partner, $from, $to)
                + $this->coordination->sumMinutes($partner, $from, $to),
            $this->signups->medianCreditedMinutes($from, $to),
        );
    }

    /**
     * El periodo sobre el que se cuentan horas: el año natural en curso.
     *
     * @return array{0: \DateTimeInterface, 1: \DateTimeInterface} inicio y fin, ambos inclusive
     */
    public function period(): array
    {
        $year = (int) date('Y');

        return [
            new \DateTime(sprintf('%d-01-01 00:00:00', $year)),
            new \DateTime(sprintf('%d-12-31 23:59:59', $year)),
        ];
    }
}
