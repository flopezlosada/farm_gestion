<?php

namespace App\Service\Volunteering;

use App\Entity\Partner;
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
    /**
     * Cuántos días se considera que alguien "acaba de llegar".
     *
     * Tres meses y no uno porque las faenas se convocan sueltas: en cuatro
     * semanas puede no haberse publicado ni una sola en su punto de recogida, y
     * entonces su cero no habla de esa persona sino del calendario.
     */
    public const NEWCOMER_DAYS = 90;

    public function __construct(
        private readonly VolunteerSignupRepository $signups,
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

        return new VolunteerContribution(
            $this->signups->sumCreditedMinutes($partner, $from, $to),
            $this->signups->medianCreditedMinutes($from, $to),
            $this->isNewcomer($partner),
        );
    }

    /**
     * Si lleva menos de {@see NEWCOMER_DAYS} en la asociación.
     *
     * SIN FECHA DE ALTA CUENTA COMO RECIÉN LLEGADA (21 de los socixs activos no
     * la tienen, vienen del histórico en papel). No es que se crea que acaban de
     * entrar: es que equivocarse por no darle el empujón a una veterana cuesta un
     * empujón, y equivocarse riñendo a quien acaba de entrar cuesta la persona.
     *
     * @param Partner $partner el socix
     *
     * @return bool true si acaba de entrar, o si no consta cuándo entró
     */
    private function isNewcomer(Partner $partner): bool
    {
        $since = $partner->getInscriptionDate();

        if (!$since instanceof \DateTimeInterface) {
            return true;
        }

        return $since > new \DateTimeImmutable(sprintf('-%d days', self::NEWCOMER_DAYS));
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
