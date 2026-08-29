<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;
use App\Repository\BasketRepository;
use App\Service\Delivery\NodeDeliveryDate;

/**
 * En qué fechas hay que copiar una tarea que se repite.
 *
 * Sólo calcula fechas: no crea nada, no toca el EntityManager. Así la regla
 * —que es toda la miga de repetir— se puede probar sin montar el flujo de
 * gestión entero, y quien la use decide qué hacer con la lista.
 *
 * SE DICE HASTA CUÁNDO, NO CUÁNTAS VECES. "El reparto de los viernes hasta fin
 * de año" es como se piensa; "cada 7 días, 17 veces" obliga a contar semanas a
 * mano y a repetir la cuenta cada vez que se amplía. El tope de
 * {@see self::MAX} sigue existiendo como red: un dedazo en el año de la fecha
 * final pediría miles de tareas.
 *
 * LA CADENCIA NO SE GUARDA EN NINGÚN SITIO. Es un parámetro de esta llamada y
 * muere aquí. Si cada copia llevara escrita la receta de la serie, anular una
 * por un festivo dejaría a las demás afirmando algo que ya no es verdad, y
 * nadie se enteraría; las copias nacen sueltas justo para que el caso del
 * festivo se arregle borrando una fila ({@see VolunteerOffer::copyForDate()}).
 *
 * LA CADENCIA {@see self::CADENCE_DELIVERY} ES LA QUE IMPORTA. El trabajo que
 * más se repite es descargar el reparto, y el reparto no cae cada siete días:
 * cae los días que ese punto de recogida reparte de verdad, que dependen de su
 * cadencia (semanal, quincenal, mensual) y de las excepciones de calendario.
 * Preguntárselo a {@see NodeDeliveryDate} —el punto único de verdad de eso— es
 * la diferencia entre unas fechas que ya vienen bien y cincuenta y dos
 * borradores que alguien tiene que repasar contra el calendario.
 */
class OfferRepeatDates
{
    /** Los días que el punto de recogida de la tarea reparte de verdad. */
    public const CADENCE_DELIVERY = 'delivery';

    /** Cada siete días. */
    public const CADENCE_WEEKLY = 'weekly';

    /** Cada catorce días. */
    public const CADENCE_BIWEEKLY = 'biweekly';

    /**
     * Una vez al mes, conservando el día de la semana y su posición: si la
     * tarea es el segundo martes, las copias son el segundo martes.
     */
    public const CADENCE_MONTHLY = 'monthly';

    /**
     * Tope duro de copias por repetición. Un año de reparto semanal cabe de
     * sobra, y por encima de eso lo más probable es que la fecha final tenga un
     * error de dedo: borrar mil tareas a mano es un castigo desproporcionado.
     */
    public const MAX = 52;

    /** Cadencias que se resuelven sumando días, y cuántos. */
    private const FIXED_DAYS = [
        self::CADENCE_WEEKLY => 7,
        self::CADENCE_BIWEEKLY => 14,
    ];

    /** Cómo se nombra cada posición del mes al pedírsela a PHP. */
    private const ORDINALS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];

    public function __construct(
        private readonly BasketRepository $baskets,
        private readonly NodeDeliveryDate $deliveryDate,
    ) {
    }

    /**
     * Las cadencias que admite una tarea concreta. `delivery` sólo se ofrece si
     * la tarea ocurre en un punto de recogida: sin nodo no hay calendario de
     * reparto al que preguntar, y ofrecerla igualmente daría cero fechas sin
     * explicar por qué.
     *
     * @param VolunteerOffer $offer la tarea que se va a repetir
     *
     * @return list<string> identificadores de cadencia válidos para esta tarea
     */
    public function cadencesFor(VolunteerOffer $offer): array
    {
        $cadences = [self::CADENCE_WEEKLY, self::CADENCE_BIWEEKLY, self::CADENCE_MONTHLY];

        if (null !== $offer->getNode()) {
            array_unshift($cadences, self::CADENCE_DELIVERY);
        }

        return $cadences;
    }

    /**
     * Las fechas en las que copiar la tarea, sin incluir la suya propia.
     *
     * Todas heredan la HORA de la tarea original. Las fechas de reparto llegan a
     * medianoche —son días, no citas—, y sin esto una serie de "los viernes a
     * las 17:00" se convertiría en diecisiete tareas de madrugada.
     *
     * @param VolunteerOffer     $offer   tarea a repetir; su `startsAt` es el punto de partida
     * @param string             $cadence una de las constantes CADENCE_*
     * @param \DateTimeInterface $until   último día que puede recibir copia, incluido
     *
     * @return list<\DateTimeImmutable> fechas ordenadas, posteriores a la tarea original
     *
     * @throws \InvalidArgumentException si la cadencia no está soportada por esta tarea
     */
    public function compute(VolunteerOffer $offer, string $cadence, \DateTimeInterface $until): array
    {
        if (!\in_array($cadence, $this->cadencesFor($offer), true)) {
            throw new \InvalidArgumentException(sprintf('Cadencia "%s" no válida para esta tarea.', $cadence));
        }

        $start = $offer->getStartsAt();
        if (null === $start) {
            return [];
        }

        $start = \DateTimeImmutable::createFromInterface($start);

        // El último día cuenta entero: quien escribe "hasta el 31 de diciembre"
        // quiere el reparto del 31, no todo lo anterior a las 00:00 de ese día.
        $until = \DateTimeImmutable::createFromInterface($until)->setTime(23, 59, 59);

        if ($until <= $start) {
            return [];
        }

        $dates = match ($cadence) {
            self::CADENCE_DELIVERY => $this->deliveryDates($offer, $start, $until),
            self::CADENCE_MONTHLY => $this->monthlyDates($start, $until),
            default => $this->fixedDates($start, $until, self::FIXED_DAYS[$cadence]),
        };

        return \array_slice($dates, 0, self::MAX);
    }

    /**
     * Fechas en las que el punto de recogida de la tarea reparte de verdad.
     *
     * La ventana de ciclos se amplía una semana por cada lado antes de filtrar
     * por la fecha física: un nodo puede repartir en un día distinto al del
     * ciclo semanal (Madrid entrega el miércoles lo del viernes), así que
     * recortar por la fecha del ciclo perdería repartos en los bordes. El
     * filtro de verdad es el de después, contra la fecha ya resuelta.
     *
     * @param VolunteerOffer     $offer tarea con nodo
     * @param \DateTimeImmutable $start arranque, excluido
     * @param \DateTimeImmutable $until final, incluido
     *
     * @return list<\DateTimeImmutable> fechas de reparto dentro del rango
     */
    private function deliveryDates(VolunteerOffer $offer, \DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $node = $offer->getNode();
        if (null === $node) {
            return [];
        }

        $dates = [];
        $cycles = $this->baskets->findBetweenDates(
            $start->modify('-7 days'),
            $until->modify('+7 days')
        );

        foreach ($cycles as $cycle) {
            $physical = $this->deliveryDate->physicalDateFor($cycle, $node);
            if (null === $physical) {
                continue;
            }

            $at = $this->atSameTimeAs($physical, $start);
            if ($at > $start && $at <= $until) {
                $dates[] = $at;
            }
        }

        return $dates;
    }

    /**
     * Fechas sumando un número fijo de días.
     *
     * @param \DateTimeImmutable $start     arranque, excluido
     * @param \DateTimeImmutable $until     final, incluido
     * @param int                $everyDays cada cuántos días
     *
     * @return list<\DateTimeImmutable>
     */
    private function fixedDates(\DateTimeImmutable $start, \DateTimeImmutable $until, int $everyDays): array
    {
        $dates = [];
        $at = $start->modify(sprintf('+%d days', $everyDays));

        while ($at <= $until && \count($dates) < self::MAX) {
            $dates[] = $at;
            $at = $at->modify(sprintf('+%d days', $everyDays));
        }

        return $dates;
    }

    /**
     * Fechas una vez al mes conservando día de la semana y posición.
     *
     * "El 15 de cada mes" no sirve aquí: una tarea de voluntariado se organiza
     * por el día de la semana (el segundo sábado), porque de eso depende quién
     * puede venir. Sumar 28 días —lo que hacía esto antes— tampoco: deriva unos
     * días cada mes y a los seis ya no cae ni en la misma semana.
     *
     * La posición se cuenta igual que en el calendario de reparto
     * ({@see NodeDeliveryDate}): días 1-7 la primera, 8-14 la segunda… y si
     * siete días después ya es otro mes, es la ÚLTIMA. Así "el último viernes"
     * sigue siendo el último aunque el mes tenga cinco.
     *
     * @param \DateTimeImmutable $start arranque, excluido
     * @param \DateTimeImmutable $until final, incluido
     *
     * @return list<\DateTimeImmutable>
     */
    private function monthlyDates(\DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $isLast = $start->modify('+7 days')->format('m') !== $start->format('m');
        $position = intdiv((int) $start->format('j') - 1, 7) + 1;
        $ordinal = $isLast ? 'last' : (self::ORDINALS[$position] ?? 'last');
        $weekday = $start->format('l');

        $dates = [];
        $month = $start->modify('first day of next month');

        while ($month <= $until && \count($dates) < self::MAX) {
            $at = $this->atSameTimeAs(
                new \DateTimeImmutable(sprintf('%s %s of %s', $ordinal, $weekday, $month->format('F Y'))),
                $start
            );

            if ($at > $start && $at <= $until) {
                $dates[] = $at;
            }

            $month = $month->modify('first day of next month');
        }

        return $dates;
    }

    /**
     * La misma fecha, a la hora de la referencia.
     *
     * @param \DateTimeImmutable $date      día a vestir de hora
     * @param \DateTimeImmutable $reference de dónde se copia la hora
     *
     * @return \DateTimeImmutable el día con la hora de la referencia
     */
    private function atSameTimeAs(\DateTimeImmutable $date, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        return $date->setTime(
            (int) $reference->format('H'),
            (int) $reference->format('i'),
            (int) $reference->format('s')
        );
    }
}
