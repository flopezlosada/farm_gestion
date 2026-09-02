<?php

namespace App\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerShift;
use App\Repository\VolunteerSignupRepository;

/**
 * A quién pedirle, con nombre y apellidos, que venga a una tarea concreta.
 *
 * NO ES LO MISMO QUE {@see VolunteerAudienceResolver}, aunque se apoye en él.
 * Aquél responde "a quién le llega el aviso de este ámbito" y alimenta un envío
 * masivo; éste responde "a quién se lo pido yo primero" y alimenta una lista
 * corta que alguien va a leer y sobre la que va a coger el teléfono. Por eso
 * ordena, recorta y explica el encaje, cosas que no tienen sentido en un envío
 * a doscientas personas.
 *
 * ORDENA POR QUIÉN MENOS HA APORTADO, Y NO ENSEÑA LA CIFRA. La distinción
 * importa y es deliberada: en la bolsa de gente ({@see \App\Controller\VolunteeringController::pool()})
 * la tabla va por nombre a propósito, porque ordenarla por aportación la
 * convierte en un ranking y un ranking expulsa a quien no puede competir. Aquí
 * el consumidor es distinto —quien coordina UNA tarea y tiene que repartir la
 * carga en vez de pedírselo siempre a las mismas cuatro— así que el orden sí
 * ayuda. Lo que no sale por pantalla es el número de nadie: saber a quién
 * llamar primero no exige publicar cuánto lleva cada cual.
 *
 * El periodo es el AÑO NATURAL, el mismo que usa el resto del módulo para
 * contar horas. Un periodo distinto aquí haría que la misma persona saliera
 * "de las que menos" en una pantalla y "de las que más" en otra.
 */
class VolunteerSuggester
{
    /**
     * Cuántas sugerencias se devuelven. Corta a propósito: una lista de
     * doscientos nombres no es una sugerencia, es la bolsa entera otra vez, y
     * nadie la lee.
     */
    public const DEFAULT_LIMIT = 8;

    public function __construct(
        private readonly VolunteerAudienceResolver $audience,
        private readonly VolunteerSignupRepository $signups,
    ) {
    }

    /**
     * Socixs a quienes tiene sentido pedirles que vengan a este turno, de quien
     * menos ha aportado este año a quien más, ya descontadxs quienes están
     * apuntadxs a él.
     *
     * Sale del ámbito MATCHING y no de toda la asociación: son quienes han
     * marcado esta área, es decir, quienes han dicho que de esto sí
     * se les avise. Sugerir a quien pidió que no le avisaran sería saltarse su
     * preferencia por la puerta de atrás.
     *
     * Una tarea sin categorías devuelve lista vacía —no hay nada con lo que
     * casar— y eso es correcto: la pantalla lo dice en vez de inventarse gente.
     *
     * El punto de recogida de cada candidatx se resuelve DESPUÉS de recortar, no
     * antes: son dos saltos perezosos por socix, y pedirlos para los doscientos
     * candidatos posibles en vez de para los ocho que se van a pintar sería
     * cuatrocientas consultas para tirar casi todas.
     *
     * @param VolunteerShift          $shift el turno para el que hace falta gente
     * @param int                     $limit cuántas sugerencias como mucho
     * @param \DateTimeInterface|null $now   momento de referencia; por defecto, ahora
     *
     * @return list<array{partner: Partner, same_node: bool}> lxs candidatxs y si recogen donde ocurre la tarea
     */
    public function forShift(VolunteerShift $shift, int $limit = self::DEFAULT_LIMIT, ?\DateTimeInterface $now = null): array
    {
        $offer = $shift->getOffer();
        if (null === $offer) {
            return [];
        }

        $candidates = $this->audience->resolve($shift, VolunteerCall::SCOPE_MATCHING);

        if ([] === $candidates) {
            return [];
        }

        $now ??= new \DateTime();
        $year = (int) $now->format('Y');
        $contributed = $this->signups->participationByPartner(
            new \DateTime(sprintf('%d-01-01 00:00:00', $year)),
            new \DateTime(sprintf('%d-12-31 23:59:59', $year))
        );

        // Quien no está en el mapa no ha hecho nada este año: cero, y por tanto
        // de lxs primerxs. Desempate por nombre para que el orden sea estable
        // entre recargas — una lista que baila sin que nada haya cambiado
        // parece rota.
        usort($candidates, static function (Partner $a, Partner $b) use ($contributed): int {
            $minutesA = $contributed[$a->getId()]['minutes'] ?? 0;
            $minutesB = $contributed[$b->getId()]['minutes'] ?? 0;

            return $minutesA <=> $minutesB
                ?: strcasecmp($a->getName().' '.$a->getSurname(), $b->getName().' '.$b->getSurname());
        });

        $node = $offer->getNode();

        return array_map(
            static fn (Partner $partner): array => [
                'partner' => $partner,
                // El encaje más fuerte que existe, y por eso se marca: quien
                // recoge su cesta ahí ya va a estar en ese sitio ese día.
                'same_node' => null !== $node && $partner->getWeeklyBasketGroup()?->getNode() === $node,
            ],
            \array_slice($candidates, 0, $limit)
        );
    }
}
